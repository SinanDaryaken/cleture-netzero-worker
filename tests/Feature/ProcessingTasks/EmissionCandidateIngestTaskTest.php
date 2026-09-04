<?php

namespace Tests\Feature\ProcessingTasks;

use App\Contracts\ProcessingTasks\Emission\CandidateArtifactStreamProvider;
use App\ProcessingTasks\Emission\CandidateArtifactVerifier;
use App\ProcessingTasks\Exceptions\PermanentProcessingTaskException;
use App\ProcessingTasks\ProcessingTaskExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use League\Flysystem\UnableToReadFile;
use RuntimeException;
use Tests\TestCase;
use Throwable;
use ZipArchive;

class EmissionCandidateIngestTaskTest extends TestCase
{
    /** @var array<string, string> */
    private const MEMBER_PATHS = [
        'entities' => 'entities.ndjson',
        'relationships' => 'relationships.ndjson',
        'findings' => 'findings.ndjson',
        'source_diff' => 'source-diff.ndjson',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'processing_tasks.connection' => 'sqlite',
            'processing_tasks.worker_id' => 'candidate-test-worker',
            'processing_tasks.lease_seconds' => 360,
            'processing_tasks.max_attempts' => 5,
            'processing_tasks.backoff_seconds' => [5, 30, 120, 300, 900],
            'emission_candidate_ingest.connection' => 'sqlite',
            'emission_candidate_ingest.supported_manifest_schema_versions' => ['1.0.0'],
            'emission_candidate_ingest.storage_profiles' => [
                'atlas.candidate_ingress' => [
                    'disk' => 'candidate_ingress',
                    'max_artifact_size_bytes' => 1024 * 1024,
                    'max_uncompressed_size_bytes' => 1024 * 1024,
                    'max_line_size_bytes' => 4096,
                    'allowed_media_types' => [
                        'application/vnd.netzeroatlas.candidate-package+zip',
                    ],
                ],
            ],
        ]);

        foreach ([
            'processing_task_failures',
            'processing_tasks',
            'emission_candidate_package_members',
            'emission_candidate_packages',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createTables();
    }

    public function test_valid_archive_is_streamed_and_verified_without_staging_writes(): void
    {
        $memberContents = $this->validMemberContents();
        $artifact = $this->archive($memberContents);
        $payload = $this->insertPackage($artifact, $memberContents);
        $this->bindArtifactStream($artifact);

        $result = app(CandidateArtifactVerifier::class)->verify(...$payload);

        $this->assertSame($payload['packageId'], $result->packageId);
        $this->assertSame($payload['expectedSha256'], $result->artifactSha256);
        $this->assertSame(strlen($artifact), $result->artifactSizeBytes);
        $this->assertSame(4, count($result->members));
    }

    public function test_checksum_mismatch_is_a_permanent_integrity_failure(): void
    {
        $memberContents = $this->validMemberContents();
        $artifact = $this->archive($memberContents);
        $payload = $this->insertPackage($artifact, $memberContents);
        $tamperedArtifact = substr_replace(
            $artifact,
            chr(ord($artifact[0]) ^ 1),
            0,
            1,
        );
        $this->bindArtifactStream($tamperedArtifact);

        $exception = $this->capturePermanentFailure(
            fn () => app(CandidateArtifactVerifier::class)->verify(...$payload),
        );

        $this->assertSame('candidate_artifact_checksum_mismatch', $exception->errorCode);
    }

    public function test_manifest_mismatch_is_rejected_before_object_storage_is_read(): void
    {
        $memberContents = $this->validMemberContents();
        $artifact = $this->archive($memberContents);
        $payload = $this->insertPackage($artifact, $memberContents);
        $manifest = json_decode(
            (string) DB::table('emission_candidate_packages')->value('manifest'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $manifest['members']['entities']['record_count'] = 99;
        DB::table('emission_candidate_packages')->update([
            'manifest' => json_encode($manifest, JSON_THROW_ON_ERROR),
        ]);
        $this->bindArtifactStream(new RuntimeException('storage must not be called'));

        $exception = $this->capturePermanentFailure(
            fn () => app(CandidateArtifactVerifier::class)->verify(...$payload),
        );

        $this->assertSame('candidate_manifest_invalid', $exception->errorCode);
    }

    public function test_member_digest_mismatch_is_a_permanent_integrity_failure(): void
    {
        $expectedContents = $this->validMemberContents();
        $actualContents = $expectedContents;
        $actualContents['entities'] = "{\"schema_version\":\"1.0.1\"}\n";
        $artifact = $this->archive($actualContents);
        $payload = $this->insertPackage($artifact, $expectedContents);
        $this->bindArtifactStream($artifact);

        $exception = $this->capturePermanentFailure(
            fn () => app(CandidateArtifactVerifier::class)->verify(...$payload),
        );

        $this->assertSame('candidate_member_digest_mismatch', $exception->errorCode);
    }

    public function test_invalid_ndjson_object_is_a_permanent_schema_failure(): void
    {
        $memberContents = $this->validMemberContents();
        $memberContents['entities'] = "not-json\n";
        $artifact = $this->archive($memberContents);
        $payload = $this->insertPackage($artifact, $memberContents);
        $this->bindArtifactStream($artifact);

        $exception = $this->capturePermanentFailure(
            fn () => app(CandidateArtifactVerifier::class)->verify(...$payload),
        );

        $this->assertSame('candidate_member_schema_invalid', $exception->errorCode);
    }

    public function test_unsupported_manifest_version_is_a_permanent_contract_failure(): void
    {
        $memberContents = $this->validMemberContents();
        $artifact = $this->archive($memberContents);
        $payload = $this->insertPackage($artifact, $memberContents, schemaVersion: '1.1.0');
        $this->bindArtifactStream($artifact);

        $exception = $this->capturePermanentFailure(
            fn () => app(CandidateArtifactVerifier::class)->verify(...$payload),
        );

        $this->assertSame('unsupported_candidate_manifest_version', $exception->errorCode);
    }

    public function test_missing_object_is_released_for_retry_without_a_failure_snapshot(): void
    {
        $memberContents = $this->validMemberContents();
        $artifact = $this->archive($memberContents);
        $payload = $this->insertPackage($artifact, $memberContents);
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask($payload, $dispatchToken);
        $this->bindArtifactStream(UnableToReadFile::fromLocation($payload['objectKey']));

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $this->assertDatabaseHas('processing_tasks', [
            'id' => $taskId,
            'status' => 'pending',
            'attempts' => 1,
            'dispatch_token' => null,
        ]);
        $this->assertDatabaseCount('processing_task_failures', 0);
    }

    public function test_temporary_storage_failure_is_released_for_retry(): void
    {
        $memberContents = $this->validMemberContents();
        $artifact = $this->archive($memberContents);
        $payload = $this->insertPackage($artifact, $memberContents);
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask($payload, $dispatchToken);
        $this->bindArtifactStream(new RuntimeException('temporary object storage outage'));

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $this->assertDatabaseHas('processing_tasks', [
            'id' => $taskId,
            'status' => 'pending',
            'attempts' => 1,
            'dispatch_token' => null,
        ]);
        $this->assertDatabaseCount('processing_task_failures', 0);
    }

    public function test_verified_artifact_is_archived_as_needing_the_staging_contract(): void
    {
        $memberContents = $this->validMemberContents();
        $artifact = $this->archive($memberContents);
        $payload = $this->insertPackage($artifact, $memberContents);
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask($payload, $dispatchToken);
        $this->bindArtifactStream($artifact);

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $failure = DB::table('processing_task_failures')->where('task_id', $taskId)->first();
        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertSame('candidate_staging_contract_requires_decision', $failure->error_code);
        $this->assertSame([
            'package_id' => $payload['packageId'],
            'artifact_sha256' => $payload['expectedSha256'],
            'artifact_size_bytes' => (string) strlen($artifact),
            'verified_member_count' => '4',
        ], json_decode($failure->payload, true, flags: JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('emission_candidate_packages', [
            'id' => $payload['packageId'],
            'status' => 'received',
        ]);
    }

    public function test_stale_dispatch_token_cannot_read_the_artifact_or_change_task_state(): void
    {
        $memberContents = $this->validMemberContents();
        $artifact = $this->archive($memberContents);
        $payload = $this->insertPackage($artifact, $memberContents);
        $currentDispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask($payload, $currentDispatchToken);
        $this->bindArtifactStream(new RuntimeException('stale dispatch reached storage'));

        app(ProcessingTaskExecutor::class)->execute($taskId, (string) Str::uuid7());

        $this->assertDatabaseHas('processing_tasks', [
            'id' => $taskId,
            'status' => 'queued',
            'attempts' => 0,
            'dispatch_token' => $currentDispatchToken,
        ]);
        $this->assertDatabaseCount('processing_task_failures', 0);
    }

    /** @return array<string, string> */
    private function validMemberContents(): array
    {
        return [
            'entities' => "{\"schema_version\":\"1.0.0\"}\n",
            'relationships' => '',
            'findings' => '',
            'source_diff' => "{\"change\":\"added\"}\n",
        ];
    }

    /** @param array<string, string> $memberContents */
    private function archive(array $memberContents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'candidate-test-');

        if ($path === false) {
            throw new RuntimeException('Candidate test archive path could not be created.');
        }

        try {
            $archive = new ZipArchive;

            if ($archive->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Candidate test archive could not be opened.');
            }

            foreach (self::MEMBER_PATHS as $memberType => $memberPath) {
                $archive->addFromString($memberPath, $memberContents[$memberType]);
            }

            $archive->close();
            $contents = file_get_contents($path);

            if (! is_string($contents)) {
                throw new RuntimeException('Candidate test archive could not be read.');
            }

            return $contents;
        } finally {
            unlink($path);
        }
    }

    /**
     * @param  array<string, string>  $memberContents
     * @return array{
     *     packageId: string,
     *     storageProfile: string,
     *     objectKey: string,
     *     objectVersionId: null,
     *     expectedSha256: string
     * }
     */
    private function insertPackage(
        string $artifact,
        array $memberContents,
        string $schemaVersion = '1.0.0',
    ): array {
        $packageId = (string) Str::uuid7();
        $producerPackageId = (string) Str::uuid7();
        $artifactSha256 = hash('sha256', $artifact);
        $objectKey = "sha256/{$artifactSha256}.zip";
        $manifestMembers = [];
        $counts = [];

        foreach (self::MEMBER_PATHS as $memberType => $memberPath) {
            $contents = $memberContents[$memberType];
            $recordCount = $contents === ''
                ? 0
                : substr_count($contents, "\n") + (str_ends_with($contents, "\n") ? 0 : 1);
            $manifestMembers[$memberType] = [
                'path' => $memberPath,
                'sha256' => hash('sha256', $contents),
                'size_bytes' => strlen($contents),
                'record_count' => $recordCount,
                'media_type' => 'application/x-ndjson',
            ];
            $counts[$memberType] = $recordCount;
        }

        $manifest = [
            'schema_version' => $schemaVersion,
            'package_id' => $producerPackageId,
            'artifact' => [
                'storage_profile' => 'atlas.candidate_ingress',
                'object_key' => $objectKey,
                'object_version_id' => null,
                'sha256' => $artifactSha256,
                'size_bytes' => strlen($artifact),
                'media_type' => 'application/vnd.netzeroatlas.candidate-package+zip',
            ],
            'members' => $manifestMembers,
            'counts' => $counts,
        ];

        DB::table('emission_candidate_packages')->insert([
            'id' => $packageId,
            'producer_package_id' => $producerPackageId,
            'schema_version' => $schemaVersion,
            'artifact_storage_profile' => 'atlas.candidate_ingress',
            'artifact_object_key' => $objectKey,
            'artifact_object_version_id' => null,
            'artifact_sha256' => $artifactSha256,
            'artifact_size_bytes' => strlen($artifact),
            'artifact_media_type' => 'application/vnd.netzeroatlas.candidate-package+zip',
            'manifest' => json_encode($manifest, JSON_THROW_ON_ERROR),
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($manifestMembers as $memberType => $member) {
            DB::table('emission_candidate_package_members')->insert([
                'id' => (string) Str::uuid7(),
                'emission_candidate_package_id' => $packageId,
                'member_type' => $memberType,
                ...$member,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'packageId' => $packageId,
            'storageProfile' => 'atlas.candidate_ingress',
            'objectKey' => $objectKey,
            'objectVersionId' => null,
            'expectedSha256' => $artifactSha256,
        ];
    }

    /**
     * @param array{
     *     packageId: string,
     *     storageProfile: string,
     *     objectKey: string,
     *     objectVersionId: null,
     *     expectedSha256: string
     * } $payload
     */
    private function insertQueuedTask(array $payload, string $dispatchToken): string
    {
        $taskId = (string) Str::uuid7();

        DB::table('processing_tasks')->insert([
            'id' => $taskId,
            'type' => 'emission.candidate.ingest',
            'payload_version' => 1,
            'tenant_id' => null,
            'payload' => json_encode([
                'package_id' => $payload['packageId'],
                'storage_profile' => $payload['storageProfile'],
                'object_key' => $payload['objectKey'],
                'object_version_id' => $payload['objectVersionId'],
                'expected_sha256' => $payload['expectedSha256'],
            ], JSON_THROW_ON_ERROR),
            'dedupe_key' => "emission-candidate:{$payload['packageId']}:ingest",
            'status' => 'queued',
            'available_at' => now(),
            'attempts' => 0,
            'dispatched_at' => now(),
            'dispatch_token' => $dispatchToken,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'claimed_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $taskId;
    }

    private function bindArtifactStream(string|Throwable $artifact): void
    {
        $this->app->instance(
            CandidateArtifactStreamProvider::class,
            new class($artifact) implements CandidateArtifactStreamProvider
            {
                public function __construct(private string|Throwable $artifact) {}

                public function open(
                    string $storageProfile,
                    string $objectKey,
                    ?string $objectVersionId,
                ): mixed {
                    if ($this->artifact instanceof Throwable) {
                        throw $this->artifact;
                    }

                    $stream = fopen('php://temp', 'w+b');

                    if ($stream === false || fwrite($stream, $this->artifact) === false) {
                        throw new RuntimeException('Candidate test stream could not be created.');
                    }

                    rewind($stream);

                    return $stream;
                }
            },
        );
    }

    /** @param callable(): mixed $operation */
    private function capturePermanentFailure(callable $operation): PermanentProcessingTaskException
    {
        try {
            $operation();
        } catch (PermanentProcessingTaskException $exception) {
            return $exception;
        }

        $this->fail('A permanent processing task failure was expected.');
    }

    private function createTables(): void
    {
        Schema::create('emission_candidate_packages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('producer_package_id');
            $table->string('schema_version');
            $table->string('artifact_storage_profile');
            $table->string('artifact_object_key');
            $table->string('artifact_object_version_id')->nullable();
            $table->string('artifact_sha256');
            $table->unsignedBigInteger('artifact_size_bytes');
            $table->string('artifact_media_type');
            $table->text('manifest');
            $table->string('status');
            $table->timestampsTz();
        });
        Schema::create('emission_candidate_package_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('emission_candidate_package_id');
            $table->string('member_type');
            $table->string('path');
            $table->string('sha256');
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedBigInteger('record_count');
            $table->string('media_type');
            $table->timestampsTz();
        });
        Schema::create('processing_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->unsignedInteger('payload_version');
            $table->uuid('tenant_id')->nullable();
            $table->text('payload');
            $table->string('dedupe_key')->unique();
            $table->string('status');
            $table->timestampTz('available_at');
            $table->unsignedInteger('attempts');
            $table->timestampTz('dispatched_at')->nullable();
            $table->uuid('dispatch_token')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->string('claimed_by')->nullable();
            $table->timestampsTz();
        });
        Schema::create('processing_task_failures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->unique();
            $table->string('type');
            $table->unsignedInteger('payload_version');
            $table->uuid('tenant_id')->nullable();
            $table->text('payload');
            $table->string('dedupe_key');
            $table->unsignedInteger('attempts');
            $table->string('error_code');
            $table->text('error_summary');
            $table->timestampTz('failed_at');
        });

    }
}
