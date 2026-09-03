<?php

namespace App\ProcessingTasks;

use App\ProcessingTasks\Exceptions\PermanentProcessingTaskException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

class ProcessingTaskExecutor
{
    public function __construct(
        private DatabaseManager $database,
        private ProcessingTaskRouter $router,
        private WorkerIdentity $workerIdentity,
    ) {}

    public function execute(string $taskId, string $dispatchToken): void
    {
        $task = $this->start($taskId, $dispatchToken);

        if ($task === null) {
            return;
        }

        try {
            Bus::dispatchSync($this->router->jobFor($task));
            $this->complete($task, $dispatchToken);
        } catch (PermanentProcessingTaskException $exception) {
            $this->archiveFailure(
                $task,
                $dispatchToken,
                $exception->errorCode,
                $exception->safeSummary,
                $exception->safePayload,
            );
        } catch (Throwable $exception) {
            Log::error('Processing task execution failed.', [
                'processing_task_id' => $task->id,
                'type' => $task->type,
                'attempts' => $task->attempts,
                'cause' => $exception::class,
            ]);

            $this->retryOrArchive($task, $dispatchToken);
        }
    }

    public function recoverTransportFailure(
        string $taskId,
        string $dispatchToken,
        ?Throwable $exception,
    ): void {
        $connection = $this->connection();

        $connection->transaction(function () use ($connection, $taskId, $dispatchToken, $exception): void {
            $row = $connection->table((string) config('processing_tasks.tables.tasks'))
                ->where('id', $taskId)
                ->where('dispatch_token', $dispatchToken)
                ->whereIn('status', ['queued', 'processing'])
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return;
            }

            $task = $this->fromRow($row);

            Log::error('Processing task transport exhausted its retries.', [
                'processing_task_id' => $task->id,
                'type' => $task->type,
                'attempts' => $task->attempts,
                'cause' => $exception === null ? null : $exception::class,
            ]);

            if ($task->attempts >= $this->router->maxAttemptsFor($task)) {
                $this->archiveLockedTask(
                    $connection,
                    $task,
                    $dispatchToken,
                    'transport_attempts_exhausted',
                    'Processing task transport exhausted its retries after the final execution attempt.',
                    $this->router->safeFailurePayload($task),
                );

                return;
            }

            $this->releaseLockedTask($connection, $task, $dispatchToken);
        });
    }

    private function start(string $taskId, string $dispatchToken): ?ProcessingTask
    {
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $taskId, $dispatchToken): ?ProcessingTask {
            $now = now();
            $row = $connection->table((string) config('processing_tasks.tables.tasks'))
                ->where('id', $taskId)
                ->where('dispatch_token', $dispatchToken)
                ->where(function (Builder $claimable) use ($now): void {
                    $claimable->where('status', 'queued')
                        ->orWhere(function (Builder $expired) use ($now): void {
                            $expired->where('status', 'processing')
                                ->where('lease_expires_at', '<=', $now);
                        });
                })
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return null;
            }

            $attempts = (int) $row->attempts + 1;

            $connection->table((string) config('processing_tasks.tables.tasks'))
                ->where('id', $taskId)
                ->where('dispatch_token', $dispatchToken)
                ->update([
                    'status' => 'processing',
                    'attempts' => $attempts,
                    'claimed_at' => $now,
                    'lease_expires_at' => $now->copy()->addSeconds(
                        $this->router->leaseSecondsFor($this->fromRow($row, $attempts)),
                    ),
                    'claimed_by' => $this->workerIdentity->value(),
                    'updated_at' => $now,
                ]);

            return $this->fromRow($row, $attempts);
        });
    }

    private function complete(ProcessingTask $task, string $dispatchToken): void
    {
        $this->connection()
            ->table((string) config('processing_tasks.tables.tasks'))
            ->where('id', $task->id)
            ->where('status', 'processing')
            ->where('dispatch_token', $dispatchToken)
            ->where('claimed_by', $this->workerIdentity->value())
            ->delete();
    }

    private function retryOrArchive(ProcessingTask $task, string $dispatchToken): void
    {
        $connection = $this->connection();

        $connection->transaction(function () use ($connection, $task, $dispatchToken): void {
            $row = $this->lockCurrentExecution($connection, $task->id, $dispatchToken);

            if ($row === null) {
                return;
            }

            if ($task->attempts >= $this->router->maxAttemptsFor($task)) {
                $this->archiveLockedTask(
                    $connection,
                    $task,
                    $dispatchToken,
                    'execution_attempts_exhausted',
                    'Processing task execution failed after its final attempt.',
                    $this->router->safeFailurePayload($task),
                );

                return;
            }

            $this->releaseLockedTask($connection, $task, $dispatchToken);
        });
    }

    /** @param array<string, string> $safePayload */
    private function archiveFailure(
        ProcessingTask $task,
        string $dispatchToken,
        string $errorCode,
        string $errorSummary,
        array $safePayload,
    ): void {
        $connection = $this->connection();

        $connection->transaction(function () use (
            $connection,
            $task,
            $dispatchToken,
            $errorCode,
            $errorSummary,
            $safePayload,
        ): void {
            if ($this->lockCurrentExecution($connection, $task->id, $dispatchToken) === null) {
                return;
            }

            $this->archiveLockedTask(
                $connection,
                $task,
                $dispatchToken,
                $errorCode,
                $errorSummary,
                $safePayload,
            );
        });
    }

    private function lockCurrentExecution(
        Connection $connection,
        string $taskId,
        string $dispatchToken,
    ): ?stdClass {
        return $connection->table((string) config('processing_tasks.tables.tasks'))
            ->where('id', $taskId)
            ->where('status', 'processing')
            ->where('dispatch_token', $dispatchToken)
            ->where('claimed_by', $this->workerIdentity->value())
            ->lockForUpdate()
            ->first();
    }

    private function releaseLockedTask(
        Connection $connection,
        ProcessingTask $task,
        string $dispatchToken,
    ): void {
        $connection->table((string) config('processing_tasks.tables.tasks'))
            ->where('id', $task->id)
            ->where('dispatch_token', $dispatchToken)
            ->update([
                'status' => 'pending',
                'available_at' => now()->addSeconds($this->retryDelay($task)),
                'dispatched_at' => null,
                'dispatch_token' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
                'claimed_by' => null,
                'updated_at' => now(),
            ]);
    }

    /** @param array<string, string> $safePayload */
    private function archiveLockedTask(
        Connection $connection,
        ProcessingTask $task,
        string $dispatchToken,
        string $errorCode,
        string $errorSummary,
        array $safePayload,
    ): void {
        $connection->table((string) config('processing_tasks.tables.failures'))->insert([
            'id' => (string) Str::uuid7(),
            'task_id' => $task->id,
            'type' => $task->type,
            'payload_version' => $task->payloadVersion,
            'tenant_id' => $task->tenantId,
            'payload' => json_encode((object) $safePayload, JSON_THROW_ON_ERROR),
            'dedupe_key' => 'sha256:'.hash('sha256', $task->dedupeKey),
            'attempts' => $task->attempts,
            'error_code' => $errorCode,
            'error_summary' => $errorSummary,
            'failed_at' => now(),
        ]);

        $connection->table((string) config('processing_tasks.tables.tasks'))
            ->where('id', $task->id)
            ->where('dispatch_token', $dispatchToken)
            ->delete();
    }

    private function retryDelay(ProcessingTask $task): int
    {
        $backoff = $this->router->backoffSecondsFor($task);
        $index = min(max($task->attempts - 1, 0), count($backoff) - 1);

        return $backoff[$index];
    }

    private function connection(): Connection
    {
        return $this->database->connection((string) config('processing_tasks.connection'));
    }

    private function fromRow(stdClass $row, ?int $attempts = null): ProcessingTask
    {
        return new ProcessingTask(
            id: (string) $row->id,
            type: (string) $row->type,
            payloadVersion: (int) $row->payload_version,
            tenantId: $row->tenant_id === null ? null : (string) $row->tenant_id,
            payload: $row->payload,
            dedupeKey: (string) $row->dedupe_key,
            attempts: $attempts ?? (int) $row->attempts,
        );
    }
}
