<?php

namespace App\ProcessingTasks\Definitions\Emission;

use App\Jobs\Emission\IngestEmissionCandidatePackage;
use App\ProcessingTasks\Contracts\ProcessingTaskDefinition;
use App\ProcessingTasks\Exceptions\PermanentProcessingTaskException;
use App\ProcessingTasks\ProcessingTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use JsonException;

class EmissionCandidateIngestTaskDefinition implements ProcessingTaskDefinition
{
    public function jobFor(ProcessingTask $task): ShouldQueue
    {
        $payload = $this->validatedPayload($task);

        return new IngestEmissionCandidatePackage(
            packageId: $payload['package_id'],
            storageProfile: $payload['storage_profile'],
            objectKey: $payload['object_key'],
            objectVersionId: $payload['object_version_id'],
            expectedSha256: $payload['expected_sha256'],
        );
    }

    public function safeFailurePayload(ProcessingTask $task): array
    {
        try {
            $payload = $this->validatedPayload($task);
        } catch (PermanentProcessingTaskException) {
            return [];
        }

        return array_filter([
            'package_id' => $payload['package_id'],
            'storage_profile' => $payload['storage_profile'],
            'object_key' => $payload['object_key'],
            'object_version_id' => $payload['object_version_id'],
            'expected_sha256' => $payload['expected_sha256'],
        ], static fn (?string $value): bool => $value !== null);
    }

    /**
     * @return array{
     *     package_id: string,
     *     storage_profile: string,
     *     object_key: string,
     *     object_version_id: string|null,
     *     expected_sha256: string
     * }
     */
    private function validatedPayload(ProcessingTask $task): array
    {
        try {
            $payload = is_string($task->payload)
                ? json_decode($task->payload, true, flags: JSON_THROW_ON_ERROR)
                : $task->payload;
        } catch (JsonException) {
            $payload = null;
        }

        $expectedKeys = [
            'expected_sha256',
            'object_key',
            'object_version_id',
            'package_id',
            'storage_profile',
        ];
        $actualKeys = is_array($payload) ? array_keys($payload) : [];
        sort($actualKeys);

        $packageId = is_array($payload) ? ($payload['package_id'] ?? null) : null;
        $storageProfile = is_array($payload) ? ($payload['storage_profile'] ?? null) : null;
        $objectKey = is_array($payload) ? ($payload['object_key'] ?? null) : null;
        $objectVersionId = is_array($payload) ? ($payload['object_version_id'] ?? null) : null;
        $expectedSha256 = is_array($payload) ? ($payload['expected_sha256'] ?? null) : null;
        $storageProfiles = config('emission_candidate_ingest.storage_profiles', []);

        if ($task->tenantId !== null
            || ! is_array($payload)
            || array_is_list($payload)
            || $actualKeys !== $expectedKeys
            || ! is_string($packageId)
            || ! Str::isUuid($packageId, 7)
            || ! is_string($storageProfile)
            || preg_match('/^[a-z][a-z0-9_]*(?:[.-][a-z0-9_]+)*$/', $storageProfile) !== 1
            || ! is_array($storageProfiles)
            || ! is_array($storageProfiles[$storageProfile] ?? null)
            || ! is_string($expectedSha256)
            || preg_match('/^[a-f0-9]{64}$/', $expectedSha256) !== 1
            || ! is_string($objectKey)
            || $objectKey !== "sha256/{$expectedSha256}.zip"
            || ($objectVersionId !== null && (! is_string($objectVersionId) || $objectVersionId === '' || strlen($objectVersionId) > 512))) {
            throw new PermanentProcessingTaskException(
                'invalid_payload',
                'Emission candidate ingest task payload does not match the registered contract.',
            );
        }

        return [
            'package_id' => $packageId,
            'storage_profile' => $storageProfile,
            'object_key' => $objectKey,
            'object_version_id' => $objectVersionId,
            'expected_sha256' => $expectedSha256,
        ];
    }
}
