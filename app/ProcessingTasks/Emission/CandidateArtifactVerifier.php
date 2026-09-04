<?php

namespace App\ProcessingTasks\Emission;

use App\Contracts\ProcessingTasks\Emission\CandidateArtifactStreamProvider;
use App\ProcessingTasks\Exceptions\PermanentProcessingTaskException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use JsonException;
use LogicException;
use RuntimeException;
use stdClass;
use ZipArchive;

class CandidateArtifactVerifier
{
    /** @var array<string, string> */
    private const MEMBER_PATHS = [
        'entities' => 'entities.ndjson',
        'relationships' => 'relationships.ndjson',
        'findings' => 'findings.ndjson',
        'source_diff' => 'source-diff.ndjson',
    ];

    public function __construct(
        private DatabaseManager $database,
        private CandidateArtifactStreamProvider $artifactStreams,
    ) {}

    public function verify(
        string $packageId,
        string $storageProfile,
        string $objectKey,
        ?string $objectVersionId,
        string $expectedSha256,
    ): CandidateArtifactVerificationResult {
        $descriptor = $this->loadDescriptor(
            $packageId,
            $storageProfile,
            $objectKey,
            $objectVersionId,
            $expectedSha256,
        );
        $artifactStream = $this->artifactStreams->open(
            $storageProfile,
            $objectKey,
            $objectVersionId,
        );

        if (! is_resource($artifactStream)) {
            throw new RuntimeException('Candidate artifact stream is not readable.');
        }

        try {
            $temporaryArtifact = tmpfile();

            if ($temporaryArtifact === false) {
                throw new RuntimeException('A temporary candidate artifact file could not be created.');
            }

            try {
                [$artifactSizeBytes, $artifactSha256] = $this->copyArtifact(
                    $artifactStream,
                    $temporaryArtifact,
                    $descriptor['artifact_size_bytes'],
                    $descriptor['max_artifact_size_bytes'],
                    $packageId,
                );

                if (! hash_equals($expectedSha256, $artifactSha256)) {
                    $this->reject(
                        'candidate_artifact_checksum_mismatch',
                        'Candidate artifact SHA-256 does not match the registered package.',
                        $packageId,
                    );
                }

                $members = $this->verifyArchive(
                    $temporaryArtifact,
                    $descriptor['members'],
                    $descriptor['max_uncompressed_size_bytes'],
                    $descriptor['max_line_size_bytes'],
                    $packageId,
                );

                return new CandidateArtifactVerificationResult(
                    packageId: $packageId,
                    artifactSha256: $artifactSha256,
                    artifactSizeBytes: $artifactSizeBytes,
                    members: $members,
                );
            } finally {
                fclose($temporaryArtifact);
            }
        } finally {
            fclose($artifactStream);
        }
    }

    /**
     * @return array{
     *     artifact_size_bytes: int,
     *     max_artifact_size_bytes: int,
     *     max_uncompressed_size_bytes: int,
     *     max_line_size_bytes: int,
     *     members: array<string, array{sha256: string, size_bytes: int, record_count: int}>
     * }
     */
    private function loadDescriptor(
        string $packageId,
        string $storageProfile,
        string $objectKey,
        ?string $objectVersionId,
        string $expectedSha256,
    ): array {
        $profiles = config('emission_candidate_ingest.storage_profiles', []);
        $profile = is_array($profiles) ? ($profiles[$storageProfile] ?? null) : null;

        if (! is_array($profile)) {
            $this->reject(
                'candidate_storage_profile_unsupported',
                'Candidate artifact storage profile is not supported by this worker.',
                $packageId,
            );
        }

        $maxArtifactSizeBytes = $this->positiveConfigurationInteger(
            $profile['max_artifact_size_bytes'] ?? null,
            'max_artifact_size_bytes',
        );
        $maxUncompressedSizeBytes = $this->positiveConfigurationInteger(
            $profile['max_uncompressed_size_bytes'] ?? null,
            'max_uncompressed_size_bytes',
        );
        $maxLineSizeBytes = $this->positiveConfigurationInteger(
            $profile['max_line_size_bytes'] ?? null,
            'max_line_size_bytes',
        );
        $package = $this->connection()
            ->table((string) config('emission_candidate_ingest.tables.packages'))
            ->where('id', $packageId)
            ->first();

        if ($package === null) {
            $this->reject(
                'candidate_package_not_found',
                'The candidate package referenced by the processing task does not exist.',
                $packageId,
            );
        }

        $registeredObjectVersionId = $package->artifact_object_version_id === null
            ? null
            : (string) $package->artifact_object_version_id;

        if ((string) $package->status !== 'received'
            || (string) $package->artifact_storage_profile !== $storageProfile
            || (string) $package->artifact_object_key !== $objectKey
            || $registeredObjectVersionId !== $objectVersionId
            || ! hash_equals((string) $package->artifact_sha256, $expectedSha256)) {
            $this->reject(
                'candidate_package_contract_mismatch',
                'Candidate processing task does not match the immutable package registry.',
                $packageId,
            );
        }

        $artifactSizeBytes = (int) $package->artifact_size_bytes;

        if ($artifactSizeBytes < 1 || $artifactSizeBytes > $maxArtifactSizeBytes) {
            $this->reject(
                'candidate_artifact_size_limit_exceeded',
                'Candidate artifact exceeds the configured compressed size limit.',
                $packageId,
            );
        }

        $allowedMediaTypes = $profile['allowed_media_types'] ?? null;

        if (! is_array($allowedMediaTypes)
            || ! in_array((string) $package->artifact_media_type, $allowedMediaTypes, true)) {
            $this->reject(
                'candidate_artifact_media_type_unsupported',
                'Candidate artifact media type is not supported by this worker.',
                $packageId,
            );
        }

        $manifest = $this->decodeManifest($package, $packageId);
        $members = $this->validateManifestAndMembers(
            $package,
            $manifest,
            $packageId,
            $artifactSizeBytes,
        );

        if (array_sum(array_column($members, 'size_bytes')) > $maxUncompressedSizeBytes) {
            $this->reject(
                'candidate_archive_expansion_limit_exceeded',
                'Candidate archive exceeds the configured uncompressed size limit.',
                $packageId,
            );
        }

        return [
            'artifact_size_bytes' => $artifactSizeBytes,
            'max_artifact_size_bytes' => $maxArtifactSizeBytes,
            'max_uncompressed_size_bytes' => $maxUncompressedSizeBytes,
            'max_line_size_bytes' => $maxLineSizeBytes,
            'members' => $members,
        ];
    }

    /** @return array<string, mixed> */
    private function decodeManifest(stdClass $package, string $packageId): array
    {
        try {
            $manifest = is_string($package->manifest)
                ? json_decode($package->manifest, true, flags: JSON_THROW_ON_ERROR)
                : $package->manifest;
        } catch (JsonException) {
            $manifest = null;
        }

        if (! is_array($manifest) || array_is_list($manifest)) {
            $this->reject(
                'candidate_manifest_invalid',
                'Candidate package manifest is not a valid object.',
                $packageId,
            );
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, array{sha256: string, size_bytes: int, record_count: int}>
     */
    private function validateManifestAndMembers(
        stdClass $package,
        array $manifest,
        string $packageId,
        int $artifactSizeBytes,
    ): array {
        $supportedVersions = config('emission_candidate_ingest.supported_manifest_schema_versions', []);
        $schemaVersion = $manifest['schema_version'] ?? null;

        if (! is_array($supportedVersions)
            || ! is_string($schemaVersion)
            || ! in_array($schemaVersion, $supportedVersions, true)
            || $schemaVersion !== (string) $package->schema_version) {
            $this->reject(
                'unsupported_candidate_manifest_version',
                'Candidate package manifest schema version is not supported by this worker.',
                $packageId,
            );
        }

        $artifact = $manifest['artifact'] ?? null;
        $manifestMembers = $manifest['members'] ?? null;
        $manifestCounts = $manifest['counts'] ?? null;

        if (! is_array($artifact)
            || array_is_list($artifact)
            || ! is_array($manifestMembers)
            || array_is_list($manifestMembers)
            || ! $this->hasExactKeys($manifestMembers, array_keys(self::MEMBER_PATHS))
            || ! is_array($manifestCounts)
            || ($manifest['package_id'] ?? null) !== (string) $package->producer_package_id
            || ($artifact['storage_profile'] ?? null) !== (string) $package->artifact_storage_profile
            || ($artifact['object_key'] ?? null) !== (string) $package->artifact_object_key
            || ($artifact['object_version_id'] ?? null) !== ($package->artifact_object_version_id === null ? null : (string) $package->artifact_object_version_id)
            || ($artifact['sha256'] ?? null) !== (string) $package->artifact_sha256
            || ($artifact['size_bytes'] ?? null) !== $artifactSizeBytes
            || ($artifact['media_type'] ?? null) !== (string) $package->artifact_media_type) {
            $this->reject(
                'candidate_manifest_invalid',
                'Candidate package manifest does not match the immutable package registry.',
                $packageId,
            );
        }

        $memberRows = $this->connection()
            ->table((string) config('emission_candidate_ingest.tables.members'))
            ->where('emission_candidate_package_id', $packageId)
            ->get();

        if ($memberRows->count() !== count(self::MEMBER_PATHS)) {
            $this->reject(
                'candidate_manifest_invalid',
                'Candidate package member registry is incomplete.',
                $packageId,
            );
        }

        $members = [];

        foreach ($memberRows as $member) {
            $memberType = (string) $member->member_type;
            $expectedPath = self::MEMBER_PATHS[$memberType] ?? null;
            $manifestMember = $manifestMembers[$memberType] ?? null;

            if ($expectedPath === null
                || isset($members[$expectedPath])
                || ! is_array($manifestMember)
                || array_is_list($manifestMember)
                || ($manifestMember['path'] ?? null) !== $expectedPath
                || (string) $member->path !== $expectedPath
                || ($manifestMember['sha256'] ?? null) !== (string) $member->sha256
                || ($manifestMember['size_bytes'] ?? null) !== (int) $member->size_bytes
                || ($manifestMember['record_count'] ?? null) !== (int) $member->record_count
                || ($manifestMember['media_type'] ?? null) !== 'application/x-ndjson'
                || (string) $member->media_type !== 'application/x-ndjson'
                || ($manifestCounts[$memberType] ?? null) !== (int) $member->record_count) {
                $this->reject(
                    'candidate_manifest_invalid',
                    'Candidate package member manifest does not match the immutable member registry.',
                    $packageId,
                );
            }

            $members[$expectedPath] = [
                'sha256' => (string) $member->sha256,
                'size_bytes' => (int) $member->size_bytes,
                'record_count' => (int) $member->record_count,
            ];
        }

        ksort($members);

        return $members;
    }

    /**
     * @param  resource  $source
     * @param  resource  $destination
     * @return array{int, string}
     */
    private function copyArtifact(
        mixed $source,
        mixed $destination,
        int $expectedSizeBytes,
        int $maxSizeBytes,
        string $packageId,
    ): array {
        $hash = hash_init('sha256');
        $sizeBytes = 0;

        while (! feof($source)) {
            $chunk = fread($source, 1024 * 1024);

            if ($chunk === false) {
                throw new RuntimeException('Candidate artifact stream could not be read.');
            }

            if ($chunk === '') {
                continue;
            }

            $sizeBytes += strlen($chunk);

            if ($sizeBytes > $maxSizeBytes) {
                $this->reject(
                    'candidate_artifact_size_limit_exceeded',
                    'Candidate artifact exceeds the configured compressed size limit.',
                    $packageId,
                );
            }

            if ($sizeBytes > $expectedSizeBytes) {
                $this->reject(
                    'candidate_artifact_size_mismatch',
                    'Candidate artifact byte size does not match the registered package.',
                    $packageId,
                );
            }

            hash_update($hash, $chunk);
            $this->writeAll($destination, $chunk);
        }

        if ($sizeBytes !== $expectedSizeBytes) {
            $this->reject(
                'candidate_artifact_size_mismatch',
                'Candidate artifact byte size does not match the registered package.',
                $packageId,
            );
        }

        return [$sizeBytes, hash_final($hash)];
    }

    /** @param resource $destination */
    private function writeAll(mixed $destination, string $contents): void
    {
        $offset = 0;
        $length = strlen($contents);

        while ($offset < $length) {
            $written = fwrite($destination, substr($contents, $offset));

            if ($written === false || $written === 0) {
                throw new RuntimeException('Candidate artifact temporary file could not be written.');
            }

            $offset += $written;
        }
    }

    /**
     * @param  resource  $temporaryArtifact
     * @param  array<string, array{sha256: string, size_bytes: int, record_count: int}>  $expectedMembers
     * @return array<string, array{sha256: string, size_bytes: int, record_count: int}>
     */
    private function verifyArchive(
        mixed $temporaryArtifact,
        array $expectedMembers,
        int $maxUncompressedSizeBytes,
        int $maxLineSizeBytes,
        string $packageId,
    ): array {
        $metadata = stream_get_meta_data($temporaryArtifact);
        $path = $metadata['uri'] ?? null;

        if (! is_string($path)) {
            throw new RuntimeException('Candidate artifact temporary file path is unavailable.');
        }

        fflush($temporaryArtifact);
        $archive = new ZipArchive;

        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            $this->reject(
                'candidate_archive_invalid',
                'Candidate artifact is not a readable ZIP archive.',
                $packageId,
            );
        }

        try {
            if ($archive->numFiles !== count(self::MEMBER_PATHS)) {
                $this->reject(
                    'candidate_archive_invalid',
                    'Candidate archive must contain exactly the four allow-listed members.',
                    $packageId,
                );
            }

            $paths = [];
            $uncompressedSizeBytes = 0;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index);
                $path = is_array($stat) ? ($stat['name'] ?? null) : null;

                if (! is_string($path)
                    || ! isset($expectedMembers[$path])
                    || isset($paths[strtolower($path)])
                    || (int) ($stat['size'] ?? -1) !== $expectedMembers[$path]['size_bytes']
                    || (isset($stat['encryption_method']) && (int) $stat['encryption_method'] !== 0)
                    || ! $this->isRegularArchiveFile($archive, $index)) {
                    $this->reject(
                        'candidate_archive_invalid',
                        'Candidate archive layout does not match the allow-listed regular files.',
                        $packageId,
                    );
                }

                $paths[strtolower($path)] = true;
                $uncompressedSizeBytes += (int) $stat['size'];

                if ($uncompressedSizeBytes > $maxUncompressedSizeBytes) {
                    $this->reject(
                        'candidate_archive_expansion_limit_exceeded',
                        'Candidate archive exceeds the configured uncompressed size limit.',
                        $packageId,
                    );
                }
            }

            $verifiedMembers = [];

            foreach ($expectedMembers as $path => $expected) {
                $actual = $this->readMember(
                    $archive,
                    $path,
                    $maxLineSizeBytes,
                    $packageId,
                );

                if (! hash_equals($expected['sha256'], $actual['sha256'])
                    || $expected['size_bytes'] !== $actual['size_bytes']
                    || $expected['record_count'] !== $actual['record_count']) {
                    $this->reject(
                        'candidate_member_digest_mismatch',
                        'Candidate archive member digest does not match its manifest descriptor.',
                        $packageId,
                    );
                }

                $verifiedMembers[$path] = $actual;
            }

            return $verifiedMembers;
        } finally {
            $archive->close();
        }
    }

    private function isRegularArchiveFile(ZipArchive $archive, int $index): bool
    {
        $operatingSystem = 0;
        $attributes = 0;

        if (! $archive->getExternalAttributesIndex($index, $operatingSystem, $attributes)) {
            return false;
        }

        if ($operatingSystem !== ZipArchive::OPSYS_UNIX) {
            return true;
        }

        $fileType = ($attributes >> 16) & 0170000;

        return $fileType === 0 || $fileType === 0100000;
    }

    /**
     * @return array{sha256: string, size_bytes: int, record_count: int}
     */
    private function readMember(
        ZipArchive $archive,
        string $path,
        int $maxLineSizeBytes,
        string $packageId,
    ): array {
        $stream = $archive->getStream($path);

        if (! is_resource($stream)) {
            $this->reject(
                'candidate_archive_invalid',
                'Candidate archive member could not be opened.',
                $packageId,
            );
        }

        try {
            $hash = hash_init('sha256');
            $sizeBytes = 0;
            $recordCount = 0;
            $lineBuffer = '';

            while (! feof($stream)) {
                $chunk = fread($stream, 65536);

                if ($chunk === false) {
                    $this->reject(
                        'candidate_archive_invalid',
                        'Candidate archive member could not be read.',
                        $packageId,
                    );
                }

                if ($chunk === '') {
                    continue;
                }

                $sizeBytes += strlen($chunk);
                hash_update($hash, $chunk);
                $lineBuffer .= $chunk;

                while (($lineBreak = strpos($lineBuffer, "\n")) !== false) {
                    $line = substr($lineBuffer, 0, $lineBreak);
                    $lineBuffer = substr($lineBuffer, $lineBreak + 1);
                    $this->validateNdjsonLine($line, $maxLineSizeBytes, $packageId);
                    $recordCount++;
                }

                if (strlen($lineBuffer) > $maxLineSizeBytes) {
                    $this->reject(
                        'candidate_member_schema_invalid',
                        'Candidate NDJSON record exceeds the configured line size limit.',
                        $packageId,
                    );
                }
            }

            if ($lineBuffer !== '') {
                $this->validateNdjsonLine($lineBuffer, $maxLineSizeBytes, $packageId);
                $recordCount++;
            }

            return [
                'sha256' => hash_final($hash),
                'size_bytes' => $sizeBytes,
                'record_count' => $recordCount,
            ];
        } finally {
            fclose($stream);
        }
    }

    private function validateNdjsonLine(
        string $line,
        int $maxLineSizeBytes,
        string $packageId,
    ): void {
        $line = str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;

        if ($line === '' || strlen($line) > $maxLineSizeBytes) {
            $this->reject(
                'candidate_member_schema_invalid',
                'Candidate NDJSON members must contain bounded, non-empty JSON object lines.',
                $packageId,
            );
        }

        try {
            $record = json_decode($line, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $record = null;
        }

        if (! is_object($record)) {
            $this->reject(
                'candidate_member_schema_invalid',
                'Candidate NDJSON members must contain valid JSON object lines.',
                $packageId,
            );
        }
    }

    private function positiveConfigurationInteger(mixed $value, string $name): int
    {
        if (! is_int($value) || $value < 1) {
            throw new LogicException("Candidate ingest {$name} must be a positive integer.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $expectedKeys
     */
    private function hasExactKeys(array $values, array $expectedKeys): bool
    {
        $actualKeys = array_keys($values);
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }

    private function connection(): Connection
    {
        return $this->database->connection(
            (string) config('emission_candidate_ingest.connection'),
        );
    }

    private function reject(string $errorCode, string $summary, string $packageId): never
    {
        throw new PermanentProcessingTaskException(
            $errorCode,
            $summary,
            ['package_id' => $packageId],
        );
    }
}
