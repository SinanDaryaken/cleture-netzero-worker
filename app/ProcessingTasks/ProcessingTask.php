<?php

namespace App\ProcessingTasks;

final readonly class ProcessingTask
{
    public function __construct(
        public string $id,
        public string $type,
        public int $payloadVersion,
        public ?string $tenantId,
        public mixed $payload,
        public string $dedupeKey,
        public int $attempts,
    ) {}
}
