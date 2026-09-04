<?php

namespace App\ProcessingTasks\Definitions\Tenancy;

use App\Enums\TenantProvisioningStatus;
use App\Jobs\Tenancy\ProvisionTenant;
use App\Models\Tenant;
use App\ProcessingTasks\Contracts\ProcessingTaskDefinition;
use App\ProcessingTasks\Contracts\ProcessingTaskLifecycle;
use App\ProcessingTasks\Exceptions\PermanentProcessingTaskException;
use App\ProcessingTasks\ProcessingTask;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use JsonException;

class TenantProvisionTaskDefinition implements ProcessingTaskDefinition, ProcessingTaskLifecycle
{
    public function jobFor(ProcessingTask $task): ShouldQueue
    {
        $this->validate($task);

        $tenant = Tenant::query()->find($task->tenantId);

        if ($tenant === null) {
            throw new PermanentProcessingTaskException(
                'tenant_not_found',
                'The tenant referenced by the processing task does not exist.',
            );
        }

        if ($tenant->provisioning_status !== TenantProvisioningStatus::Provisioning) {
            throw new PermanentProcessingTaskException(
                'invalid_tenant_state',
                'The tenant is not in the provisioning state.',
            );
        }

        return new ProvisionTenant((string) $task->tenantId);
    }

    public function safeFailurePayload(ProcessingTask $task): array
    {
        return [];
    }

    public function starting(Connection $connection, ProcessingTask $task): void
    {
        if ($task->tenantId === null) {
            return;
        }

        $connection->table('tenants')
            ->where('id', $task->tenantId)
            ->where('provisioning_status', TenantProvisioningStatus::Pending->value)
            ->update([
                'provisioning_status' => TenantProvisioningStatus::Provisioning->value,
                'active' => false,
                'updated_at' => now(),
            ]);
    }

    public function completed(Connection $connection, ProcessingTask $task): void
    {
        $tenant = $connection->table('tenants')
            ->where('id', $task->tenantId)
            ->lockForUpdate()
            ->first();

        if ($tenant === null
            || $tenant->provisioning_status !== TenantProvisioningStatus::Provisioning->value
            || $tenant->schema_version === null) {
            throw new PermanentProcessingTaskException(
                'tenant_completion_rejected',
                'The provisioned tenant could not be activated from its current state.',
            );
        }

        $connection->table('tenants')
            ->where('id', $task->tenantId)
            ->update([
                'provisioning_status' => TenantProvisioningStatus::Ready->value,
                'active' => true,
                'updated_at' => now(),
            ]);
    }

    public function failed(Connection $connection, ProcessingTask $task): void
    {
        if ($task->tenantId === null) {
            return;
        }

        $connection->table('tenants')
            ->where('id', $task->tenantId)
            ->whereIn('provisioning_status', [
                TenantProvisioningStatus::Pending->value,
                TenantProvisioningStatus::Provisioning->value,
            ])
            ->update([
                'provisioning_status' => TenantProvisioningStatus::Failed->value,
                'active' => false,
                'updated_at' => now(),
            ]);
    }

    private function validate(ProcessingTask $task): void
    {
        try {
            $payload = is_string($task->payload)
                ? json_decode($task->payload, true, flags: JSON_THROW_ON_ERROR)
                : $task->payload;
        } catch (JsonException) {
            $payload = null;
        }

        if ($task->tenantId === null
            || ! Str::isUuid($task->tenantId, 7)
            || ! is_array($payload)
            || $payload !== []) {
            throw new PermanentProcessingTaskException(
                'invalid_payload',
                'Tenant provisioning task payload does not match the registered contract.',
            );
        }
    }
}
