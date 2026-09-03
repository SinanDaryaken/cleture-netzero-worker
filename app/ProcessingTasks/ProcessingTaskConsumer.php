<?php

namespace App\ProcessingTasks;

use App\Jobs\ProcessProcessingTask;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use stdClass;
use Throwable;

class ProcessingTaskConsumer
{
    public function __construct(
        private DatabaseManager $database,
        private QueueManager $queue,
        private ProcessingTaskRouter $router,
    ) {}

    public function consume(int $limit): ProcessingTaskRunResult
    {
        $this->assertAtomicQueueConfiguration();

        $selected = 0;
        $enqueued = 0;
        $retried = 0;
        $failed = 0;

        for ($index = 0; $index < $limit; $index++) {
            try {
                $outcome = $this->dispatchNext();
            } catch (Throwable $exception) {
                Log::error('Processing task dispatch failed.', [
                    'cause' => $exception::class,
                ]);

                $retried++;

                break;
            }

            if ($outcome === null) {
                break;
            }

            $selected++;

            if ($outcome === 'failed') {
                $failed++;
            } else {
                $enqueued++;
            }
        }

        return new ProcessingTaskRunResult(
            claimed: $selected,
            enqueued: $enqueued,
            retried: $retried,
            failed: $failed,
        );
    }

    private function dispatchNext(): ?string
    {
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection): ?string {
            $now = now();
            $staleDispatchTime = $now->copy()->subSeconds(
                (int) config('processing_tasks.dispatch.stale_after_seconds'),
            );
            $query = $connection->table((string) config('processing_tasks.tables.tasks'));

            $this->applySupportedContracts($query);

            $row = $query
                ->where(function (Builder $eligible) use ($now, $staleDispatchTime): void {
                    $eligible->where(function (Builder $pending) use ($now): void {
                        $pending->where('status', 'pending')
                            ->where('available_at', '<=', $now);
                    })->orWhere(function (Builder $queued) use ($staleDispatchTime): void {
                        $queued->where('status', 'queued')
                            ->where('dispatched_at', '<=', $staleDispatchTime);
                    })->orWhere(function (Builder $processing) use ($now): void {
                        $processing->where('status', 'processing')
                            ->where('lease_expires_at', '<=', $now);
                    });
                })
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();

            if ($row === null) {
                return null;
            }

            $task = $this->fromRow($row);

            if ($row->status === 'processing'
                && $task->attempts >= $this->router->maxAttemptsFor($task)) {
                $this->archiveExpiredTask($connection, $task);

                return 'failed';
            }

            $dispatchToken = (string) Str::uuid7();

            $connection->table((string) config('processing_tasks.tables.tasks'))
                ->where('id', $task->id)
                ->update([
                    'status' => 'queued',
                    'dispatched_at' => $now,
                    'dispatch_token' => $dispatchToken,
                    'claimed_at' => null,
                    'lease_expires_at' => null,
                    'claimed_by' => null,
                    'updated_at' => $now,
                ]);

            $this->queue->connection((string) config('processing_tasks.queue_connection'))
                ->push(
                    new ProcessProcessingTask($task->id, $dispatchToken),
                    '',
                    $this->router->queueFor($task),
                );

            return 'enqueued';
        });
    }

    private function applySupportedContracts(Builder $query): void
    {
        $supportedContracts = $this->router->supportedContracts();

        if ($supportedContracts === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $supported) use ($supportedContracts): void {
            foreach ($supportedContracts as $contract) {
                $supported->orWhere(function (Builder $candidate) use ($contract): void {
                    $candidate->where('type', $contract['type'])
                        ->where('payload_version', $contract['payload_version']);
                });
            }
        });
    }

    private function archiveExpiredTask(Connection $connection, ProcessingTask $task): void
    {
        $connection->table((string) config('processing_tasks.tables.failures'))->insert([
            'id' => (string) Str::uuid7(),
            'task_id' => $task->id,
            'type' => $task->type,
            'payload_version' => $task->payloadVersion,
            'tenant_id' => $task->tenantId,
            'payload' => json_encode($this->router->safeFailurePayload($task), JSON_THROW_ON_ERROR),
            'dedupe_key' => 'sha256:'.hash('sha256', $task->dedupeKey),
            'attempts' => $task->attempts,
            'error_code' => 'lease_attempts_exhausted',
            'error_summary' => 'Processing task lease expired after its final attempt.',
            'failed_at' => now(),
        ]);

        $connection->table((string) config('processing_tasks.tables.tasks'))
            ->where('id', $task->id)
            ->delete();
    }

    private function assertAtomicQueueConfiguration(): void
    {
        $queueConnection = (string) config('processing_tasks.queue_connection');
        $queueConfiguration = config("queue.connections.{$queueConnection}");
        $queueDatabaseConnection = is_array($queueConfiguration)
            ? ($queueConfiguration['connection'] ?? config('database.default'))
            : null;

        if (! is_array($queueConfiguration)
            || ($queueConfiguration['driver'] ?? null) !== 'database'
            || ($queueConfiguration['after_commit'] ?? null) !== false
            || $queueDatabaseConnection !== config('processing_tasks.connection')) {
            throw new LogicException(
                'Processing task dispatch requires a non-deferred database queue on the processing database connection.',
            );
        }
    }

    private function connection(): Connection
    {
        return $this->database->connection((string) config('processing_tasks.connection'));
    }

    private function fromRow(stdClass $row): ProcessingTask
    {
        return new ProcessingTask(
            id: (string) $row->id,
            type: (string) $row->type,
            payloadVersion: (int) $row->payload_version,
            tenantId: $row->tenant_id === null ? null : (string) $row->tenant_id,
            payload: $row->payload,
            dedupeKey: (string) $row->dedupe_key,
            attempts: (int) $row->attempts,
        );
    }
}
