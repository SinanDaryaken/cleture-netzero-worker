<?php

namespace App\ProcessingTasks\Exceptions;

use RuntimeException;

class PermanentProcessingTaskException extends RuntimeException
{
    /** @param array<string, string> $safePayload */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $safeSummary,
        public readonly array $safePayload = [],
    ) {
        parent::__construct($safeSummary);
    }
}
