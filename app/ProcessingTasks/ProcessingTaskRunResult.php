<?php

namespace App\ProcessingTasks;

final readonly class ProcessingTaskRunResult
{
    public function __construct(
        public int $claimed,
        public int $enqueued,
        public int $retried,
        public int $failed,
    ) {}
}
