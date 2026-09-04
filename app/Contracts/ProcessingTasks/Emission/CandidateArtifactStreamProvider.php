<?php

namespace App\Contracts\ProcessingTasks\Emission;

interface CandidateArtifactStreamProvider
{
    /** @return resource */
    public function open(
        string $storageProfile,
        string $objectKey,
        ?string $objectVersionId,
    ): mixed;
}
