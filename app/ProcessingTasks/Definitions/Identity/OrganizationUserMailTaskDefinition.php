<?php

namespace App\ProcessingTasks\Definitions\Identity;

use App\Models\OrganizationUser;
use App\ProcessingTasks\Contracts\ProcessingTaskDefinition;
use App\ProcessingTasks\Exceptions\PermanentProcessingTaskException;
use App\ProcessingTasks\ProcessingTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;
use JsonException;

abstract class OrganizationUserMailTaskDefinition implements ProcessingTaskDefinition
{
    /** @var list<string> */
    private const SUPPORTED_LOCALES = ['tr', 'en'];

    final public function jobFor(ProcessingTask $task): ShouldQueue
    {
        $payload = $this->validatedPayload($task);
        $organizationUserId = $payload['organizationUserId'];

        if (! OrganizationUser::query()->whereKey($organizationUserId)->exists()) {
            throw new PermanentProcessingTaskException(
                'organization_user_not_found',
                'The organization user referenced by the processing task does not exist.',
                $payload,
            );
        }

        return $this->makeJob($organizationUserId, $payload['locale'] ?? null);
    }

    final public function safeFailurePayload(ProcessingTask $task): array
    {
        try {
            return $this->validatedPayload($task);
        } catch (PermanentProcessingTaskException) {
            return [];
        }
    }

    abstract protected function makeJob(string $organizationUserId, ?string $locale): ShouldQueue;

    /** @return array{organizationUserId: string, locale?: string} */
    private function validatedPayload(ProcessingTask $task): array
    {
        try {
            $payload = is_string($task->payload)
                ? json_decode($task->payload, true, flags: JSON_THROW_ON_ERROR)
                : $task->payload;
        } catch (JsonException) {
            $payload = null;
        }

        $expectedKeys = match ($task->payloadVersion) {
            1 => ['organizationUserId'],
            2 => ['organizationUserId', 'locale'],
            default => [],
        };

        if (! is_array($payload)
            || array_is_list($payload)
            || ! $this->hasExactKeys($payload, $expectedKeys)
            || ! is_string($payload['organizationUserId'])
            || ! Str::isUuid($payload['organizationUserId'])
            || ($task->payloadVersion === 2 && (
                ! is_string($payload['locale'])
                || ! in_array($payload['locale'], self::SUPPORTED_LOCALES, true)
            ))) {
            throw new PermanentProcessingTaskException(
                'invalid_payload',
                'Processing task payload does not match the registered contract.',
            );
        }

        if ($task->payloadVersion === 1) {
            return ['organizationUserId' => $payload['organizationUserId']];
        }

        return [
            'organizationUserId' => $payload['organizationUserId'],
            'locale' => $payload['locale'],
        ];
    }

    /**
     * @param  array<mixed>  $payload
     * @param  list<string>  $expectedKeys
     */
    private function hasExactKeys(array $payload, array $expectedKeys): bool
    {
        $actualKeys = array_keys($payload);
        sort($actualKeys);
        sort($expectedKeys);

        return $actualKeys === $expectedKeys;
    }
}
