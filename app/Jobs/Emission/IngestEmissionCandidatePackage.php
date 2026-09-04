<?php

namespace App\Jobs\Emission;

use App\ProcessingTasks\Emission\CandidateArtifactVerifier;
use App\ProcessingTasks\Exceptions\PermanentProcessingTaskException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestEmissionCandidatePackage implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $packageId,
        public readonly string $storageProfile,
        public readonly string $objectKey,
        public readonly ?string $objectVersionId,
        public readonly string $expectedSha256,
    ) {}

    public function handle(CandidateArtifactVerifier $verifier): void
    {
        $verification = $verifier->verify(
            packageId: $this->packageId,
            storageProfile: $this->storageProfile,
            objectKey: $this->objectKey,
            objectVersionId: $this->objectVersionId,
            expectedSha256: $this->expectedSha256,
        );

        throw new PermanentProcessingTaskException(
            'candidate_staging_contract_requires_decision',
            'Candidate artifact integrity was verified, but the durable staging writer is not available in this worker version.',
            $verification->safePayload(),
        );
    }
}
