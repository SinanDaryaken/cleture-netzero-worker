<?php

namespace App\ProcessingTasks\Contracts;

use App\ProcessingTasks\ProcessingTask;
use Illuminate\Database\Connection;

interface ProcessingTaskLifecycle
{
    public function starting(Connection $connection, ProcessingTask $task): void;

    public function completed(Connection $connection, ProcessingTask $task): void;

    public function failed(Connection $connection, ProcessingTask $task): void;
}
