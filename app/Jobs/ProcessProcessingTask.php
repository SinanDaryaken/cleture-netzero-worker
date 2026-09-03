<?php

namespace App\Jobs;

use App\ProcessingTasks\ProcessingTaskExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessProcessingTask implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $processingTaskId,
        public readonly string $dispatchToken,
    ) {}

    public function handle(ProcessingTaskExecutor $executor): void
    {
        $executor->execute($this->processingTaskId, $this->dispatchToken);
    }

    public function failed(?Throwable $exception): void
    {
        app(ProcessingTaskExecutor::class)->recoverTransportFailure(
            $this->processingTaskId,
            $this->dispatchToken,
            $exception,
        );
    }
}
