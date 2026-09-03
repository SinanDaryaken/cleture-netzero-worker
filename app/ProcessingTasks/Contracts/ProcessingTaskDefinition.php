<?php

namespace App\ProcessingTasks\Contracts;

use App\ProcessingTasks\ProcessingTask;
use Illuminate\Contracts\Queue\ShouldQueue;

interface ProcessingTaskDefinition
{
    public function jobFor(ProcessingTask $task): ShouldQueue;

    /** @return array<string, string> */
    public function safeFailurePayload(ProcessingTask $task): array;
}
