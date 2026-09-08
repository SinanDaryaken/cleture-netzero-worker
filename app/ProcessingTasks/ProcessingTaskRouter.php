<?php

namespace App\ProcessingTasks;

use App\ProcessingTasks\Contracts\ProcessingTaskDefinition;
use App\ProcessingTasks\Contracts\ProcessingTaskLifecycle;
use App\ProcessingTasks\Exceptions\PermanentProcessingTaskException;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use LogicException;

class ProcessingTaskRouter
{
    public const EMAIL_VERIFICATION = 'organization-user.email-verification';

    public const PASSWORD_CHANGED = 'organization-user.password-changed';

    public const PASSWORD_RESET = 'organization-user.password-reset';

    public const TENANT_PROVISION = 'tenant.provision';

    public function __construct(private Container $container) {}

    /** @return list<array{type: string, payload_version: int}> */
    public function supportedContracts(): array
    {
        $supportedContracts = [];

        foreach ($this->contracts() as $type => $versions) {
            foreach (array_keys($versions) as $payloadVersion) {
                $supportedContracts[] = [
                    'type' => $type,
                    'payload_version' => (int) $payloadVersion,
                ];
            }
        }

        return $supportedContracts;
    }

    public function jobFor(ProcessingTask $task): ShouldQueue
    {
        return $this->definitionFor($task->type, $task->payloadVersion)->jobFor($task);
    }

    public function queueFor(ProcessingTask $task): string
    {
        $contract = $this->contractFor($task->type, $task->payloadVersion);
        $queue = $contract['queue'] ?? null;

        if (! is_string($queue) || trim($queue) === '') {
            throw new LogicException('Every processing task contract must define a queue.');
        }

        return trim($queue);
    }

    public function maxAttemptsFor(ProcessingTask $task): int
    {
        return $this->positiveIntegerOption(
            $this->contractFor($task->type, $task->payloadVersion)['max_attempts']
                ?? config('processing_tasks.max_attempts'),
            'max_attempts',
        );
    }

    public function leaseSecondsFor(ProcessingTask $task): int
    {
        return $this->positiveIntegerOption(
            $this->contractFor($task->type, $task->payloadVersion)['lease_seconds']
                ?? config('processing_tasks.lease_seconds'),
            'lease_seconds',
        );
    }

    /** @return list<int> */
    public function backoffSecondsFor(ProcessingTask $task): array
    {
        $backoff = $this->contractFor($task->type, $task->payloadVersion)['backoff_seconds']
            ?? config('processing_tasks.backoff_seconds');

        if (! is_array($backoff)
            || ! array_is_list($backoff)
            || $backoff === []
            || collect($backoff)->contains(fn (mixed $delay): bool => ! is_int($delay) || $delay < 1)) {
            throw new LogicException('Processing task backoff_seconds must be a non-empty list of positive integers.');
        }

        return $backoff;
    }

    /** @return array<string, string> */
    public function safeFailurePayload(ProcessingTask $task): array
    {
        try {
            return $this->definitionFor($task->type, $task->payloadVersion)
                ->safeFailurePayload($task);
        } catch (PermanentProcessingTaskException) {
            return [];
        }
    }

    public function starting(Connection $connection, ProcessingTask $task): void
    {
        $lifecycle = $this->lifecycleFor($task);
        $lifecycle?->starting($connection, $task);
    }

    public function completed(Connection $connection, ProcessingTask $task): void
    {
        $lifecycle = $this->lifecycleFor($task);
        $lifecycle?->completed($connection, $task);
    }

    public function failed(Connection $connection, ProcessingTask $task): void
    {
        $lifecycle = $this->lifecycleFor($task);
        $lifecycle?->failed($connection, $task);
    }

    private function definitionFor(string $type, int $payloadVersion): ProcessingTaskDefinition
    {
        $contract = $this->contractFor($type, $payloadVersion);
        $definitionClass = $contract['definition'] ?? null;

        if (! is_string($definitionClass) || ! is_a($definitionClass, ProcessingTaskDefinition::class, true)) {
            throw new LogicException('Every processing task contract must define a valid task definition.');
        }

        return $this->container->make($definitionClass);
    }

    private function lifecycleFor(ProcessingTask $task): ?ProcessingTaskLifecycle
    {
        $definition = $this->definitionFor($task->type, $task->payloadVersion);

        return $definition instanceof ProcessingTaskLifecycle ? $definition : null;
    }

    /** @return array{definition: class-string<ProcessingTaskDefinition>, queue: string, max_attempts?: int, lease_seconds?: int, backoff_seconds?: list<int>} */
    private function contractFor(string $type, int $payloadVersion): array
    {
        $contract = $this->contracts()[$type][$payloadVersion] ?? null;

        if (! is_array($contract)) {
            throw new PermanentProcessingTaskException(
                'unsupported_task_contract',
                'Processing task type and payload version are not supported by this worker.',
            );
        }

        return $contract;
    }

    /** @return array<string, array<int, array{definition: class-string<ProcessingTaskDefinition>, queue: string, max_attempts?: int, lease_seconds?: int, backoff_seconds?: list<int>}>> */
    private function contracts(): array
    {
        $contracts = config('processing_tasks.contracts', []);

        if (! is_array($contracts)) {
            throw new LogicException('Processing task contracts must be configured as an array.');
        }

        return $contracts;
    }

    private function positiveIntegerOption(mixed $value, string $option): int
    {
        if (! is_int($value) || $value < 1) {
            throw new LogicException("Processing task {$option} must be a positive integer.");
        }

        return $value;
    }
}
