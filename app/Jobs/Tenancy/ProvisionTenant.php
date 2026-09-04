<?php

namespace App\Jobs\Tenancy;

use App\Enums\TenantProvisioningStatus;
use App\Models\Tenant;
use App\ProcessingTasks\Exceptions\PermanentProcessingTaskException;
use App\Tenancy\Contracts\TenantDatabaseProvisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionTenant implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $tenantId) {}

    public function handle(TenantDatabaseProvisioner $provisioner): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            throw new PermanentProcessingTaskException(
                'tenant_not_found',
                'The tenant referenced by the provisioning job does not exist.',
            );
        }

        if ($tenant->provisioning_status !== TenantProvisioningStatus::Provisioning) {
            throw new PermanentProcessingTaskException(
                'invalid_tenant_state',
                'The tenant is not in the provisioning state.',
            );
        }

        $tenant->update([
            'schema_version' => $provisioner->provision($tenant),
        ]);
    }
}
