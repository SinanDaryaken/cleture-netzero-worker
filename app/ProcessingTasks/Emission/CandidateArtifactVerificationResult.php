<?php

namespace App\ProcessingTasks\Emission;

final readonly class CandidateArtifactVerificationResult
{
    /**
     * @param  array<string, array{sha256: string, size_bytes: int, record_count: int}>  $members
     */
    public function __construct(
        public string $packageId,
        public string $artifactSha256,
        public int $artifactSizeBytes,
        public array $members,
    ) {}

    /** @return array<string, string> */
    public function safePayload(): array
    {
        return [
            'package_id' => $this->packageId,
            'artifact_sha256' => $this->artifactSha256,
            'artifact_size_bytes' => (string) $this->artifactSizeBytes,
            'verified_member_count' => (string) count($this->members),
        ];
    }
}
