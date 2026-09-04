<?php

namespace Tests\Unit\ProcessingTasks\Emission;

use App\ProcessingTasks\Emission\LaravelFilesystemCandidateArtifactStreamProvider;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use LogicException;
use Mockery;
use Tests\TestCase;

class LaravelFilesystemCandidateArtifactStreamProviderTest extends TestCase
{
    public function test_unversioned_read_uses_the_configured_disk(): void
    {
        $objectKey = 'sha256/abc123.zip';
        $stream = fopen('php://temp', 'w+b');

        $this->assertIsResource($stream);

        config([
            'emission_candidate_ingest.storage_profiles.atlas.candidate_ingress.disk' => 'candidate_ingress',
        ]);

        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('readStream')
            ->once()
            ->with($objectKey)
            ->andReturn($stream);
        $filesystems = Mockery::mock(FilesystemManager::class);
        $filesystems->shouldReceive('disk')
            ->once()
            ->with('candidate_ingress')
            ->andReturn($disk);
        $filesystems->shouldNotReceive('build');

        $actual = (new LaravelFilesystemCandidateArtifactStreamProvider($filesystems))->open(
            'atlas.candidate_ingress',
            $objectKey,
            null,
        );

        $this->assertSame($stream, $actual);

        fclose($stream);
    }

    public function test_versioned_s3_read_builds_an_isolated_disk_with_the_requested_version(): void
    {
        $objectKey = 'sha256/abc123.zip';
        $objectVersionId = 'version-42';
        $stream = fopen('php://temp', 'w+b');

        $this->assertIsResource($stream);

        config([
            'emission_candidate_ingest.storage_profiles.atlas.candidate_ingress.disk' => 'candidate_ingress',
            'filesystems.disks.candidate_ingress' => [
                'driver' => 's3',
                'key' => 'test-key',
                'secret' => 'test-secret',
                'bucket' => 'candidate-ingress',
                'options' => [
                    'ChecksumMode' => 'ENABLED',
                ],
            ],
        ]);

        $versionedDisk = Mockery::mock(FilesystemAdapter::class);
        $versionedDisk->shouldReceive('readStream')
            ->once()
            ->with($objectKey)
            ->andReturn($stream);
        $filesystems = Mockery::mock(FilesystemManager::class);
        $filesystems->shouldNotReceive('disk');
        $filesystems->shouldReceive('build')
            ->once()
            ->with(Mockery::on(function (array $configuration) use ($objectVersionId): bool {
                $this->assertSame('s3', $configuration['driver']);
                $this->assertSame('ENABLED', $configuration['options']['ChecksumMode']);
                $this->assertSame($objectVersionId, $configuration['options']['VersionId']);

                return true;
            }))
            ->andReturn($versionedDisk);

        $actual = (new LaravelFilesystemCandidateArtifactStreamProvider($filesystems))->open(
            'atlas.candidate_ingress',
            $objectKey,
            $objectVersionId,
        );

        $this->assertSame($stream, $actual);

        fclose($stream);
    }

    public function test_versioned_read_rejects_a_non_s3_disk(): void
    {
        config([
            'emission_candidate_ingest.storage_profiles.atlas.candidate_ingress.disk' => 'candidate_ingress',
            'filesystems.disks.candidate_ingress' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/candidate-ingress'),
            ],
        ]);

        $filesystems = Mockery::mock(FilesystemManager::class);
        $filesystems->shouldNotReceive('disk');
        $filesystems->shouldNotReceive('build');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Version-pinned candidate artifacts require an S3-compatible filesystem disk.',
        );

        (new LaravelFilesystemCandidateArtifactStreamProvider($filesystems))->open(
            'atlas.candidate_ingress',
            'sha256/abc123.zip',
            'version-42',
        );
    }
}
