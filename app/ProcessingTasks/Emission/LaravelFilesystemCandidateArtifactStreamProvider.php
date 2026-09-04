<?php

namespace App\ProcessingTasks\Emission;

use App\Contracts\ProcessingTasks\Emission\CandidateArtifactStreamProvider;
use Illuminate\Filesystem\FilesystemManager;
use LogicException;

class LaravelFilesystemCandidateArtifactStreamProvider implements CandidateArtifactStreamProvider
{
    public function __construct(private FilesystemManager $filesystems) {}

    /** @return resource */
    public function open(
        string $storageProfile,
        string $objectKey,
        ?string $objectVersionId,
    ): mixed {
        $profiles = config('emission_candidate_ingest.storage_profiles', []);
        $profile = is_array($profiles) ? ($profiles[$storageProfile] ?? null) : null;
        $diskName = is_array($profile) ? ($profile['disk'] ?? null) : null;

        if (! is_string($diskName) || trim($diskName) === '') {
            throw new LogicException('Candidate artifact storage profile must define a filesystem disk.');
        }

        if ($objectVersionId === null) {
            $filesystem = $this->filesystems->disk($diskName);
        } else {
            $diskConfiguration = config("filesystems.disks.{$diskName}");

            if (! is_array($diskConfiguration) || ($diskConfiguration['driver'] ?? null) !== 's3') {
                throw new LogicException('Version-pinned candidate artifacts require an S3-compatible filesystem disk.');
            }

            $options = $diskConfiguration['options'] ?? [];

            if (! is_array($options)) {
                throw new LogicException('Candidate artifact filesystem options must be an array.');
            }

            $diskConfiguration['options'] = [
                ...$options,
                'VersionId' => $objectVersionId,
            ];
            $filesystem = $this->filesystems->build($diskConfiguration);
        }

        $stream = $filesystem->readStream($objectKey);

        if (! is_resource($stream)) {
            throw new LogicException('Candidate artifact filesystem did not return a readable stream.');
        }

        return $stream;
    }
}
