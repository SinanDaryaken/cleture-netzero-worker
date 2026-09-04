<?php

namespace App\Console\Commands;

use App\Enums\TenantProvisioningStatus;
use App\Models\Tenant;
use App\Tenancy\Contracts\TenantSchemaMigrator;
use DateInterval;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Contracts\Console\Isolatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

#[Signature('tenant-schemas:upgrade
    {--tenant=* : Upgrade only the specified tenant UUIDs}
    {--chunk=100 : Number of tenants to load from the central database at once}
    {--force : Run without a production confirmation prompt}')]
#[Description('Upgrade ready tenant databases to the canonical tenant migration set')]
class UpgradeTenantSchemas extends Command implements Isolatable
{
    use ConfirmableTrait;

    public function handle(TenantSchemaMigrator $migrator): int
    {
        $chunkSize = $this->chunkSize();
        $tenantIds = $this->tenantIds();

        if ($chunkSize === null) {
            $this->components->error('The --chunk option must be an integer between 1 and 1000.');

            return self::FAILURE;
        }

        if ($tenantIds === null) {
            $this->components->error('Every --tenant option must be a UUIDv7 tenant identifier.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed('Ready tenant databases will be upgraded.')) {
            return self::FAILURE;
        }

        if ($tenantIds !== [] && ! $this->requestedTenantsAreReady($tenantIds)) {
            return self::FAILURE;
        }

        $targetVersion = $migrator->targetVersion();
        $scopeQuery = Tenant::query()
            ->where('provisioning_status', TenantProvisioningStatus::Ready->value);

        if ($tenantIds !== []) {
            $scopeQuery->whereKey($tenantIds);
        }

        $scopedTenantCount = (clone $scopeQuery)->count();
        $query = (clone $scopeQuery)
            ->select(['id', 'schema_version', 'provisioning_status'])
            ->where(function ($query) use ($targetVersion): void {
                $query
                    ->whereNull('schema_version')
                    ->orWhere('schema_version', '<>', $targetVersion);
            });
        $eligibleTenantCount = (clone $query)->count();
        $currentTenantCount = $scopedTenantCount - $eligibleTenantCount;

        $this->components->info(sprintf(
            'Target schema %s; %d tenant(s) require an upgrade.',
            $targetVersion,
            $eligibleTenantCount,
        ));

        $upgradedTenantCount = 0;
        $failedTenantCount = 0;

        $query->chunkById(
            $chunkSize,
            function (Collection $tenants) use (
                $migrator,
                $targetVersion,
                &$upgradedTenantCount,
                &$failedTenantCount,
            ): void {
                foreach ($tenants as $tenant) {
                    try {
                        $appliedVersion = $migrator->migrate($tenant);

                        if (! hash_equals($targetVersion, $appliedVersion)) {
                            throw new LogicException('The migrated tenant did not reach the target schema version.');
                        }

                        $updated = Tenant::query()
                            ->whereKey($tenant->getKey())
                            ->where('provisioning_status', TenantProvisioningStatus::Ready->value)
                            ->update([
                                'schema_version' => $appliedVersion,
                                'updated_at' => now(),
                            ]);

                        if ($updated !== 1) {
                            throw new LogicException('The tenant left the ready state during its schema upgrade.');
                        }

                        $upgradedTenantCount++;
                        $this->components->info("Tenant {$tenant->getKey()} upgraded.");
                    } catch (Throwable $exception) {
                        $failedTenantCount++;
                        report($exception);
                        $this->components->error(sprintf(
                            'Tenant %s failed: %s',
                            $tenant->getKey(),
                            $exception->getMessage(),
                        ));
                    }
                }
            },
            column: 'id',
        );

        $this->components->info(sprintf(
            'Tenant schema upgrade finished: %d upgraded, %d failed, %d already current.',
            $upgradedTenantCount,
            $failedTenantCount,
            $currentTenantCount,
        ));

        return $failedTenantCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    public function isolatableId(): string
    {
        return 'tenant-schemas:upgrade';
    }

    public function isolationLockExpiresAt(): DateInterval
    {
        return new DateInterval('PT6H');
    }

    private function chunkSize(): ?int
    {
        $option = $this->option('chunk');

        if (! is_string($option) || preg_match('/^[0-9]+$/', $option) !== 1) {
            return null;
        }

        $chunkSize = (int) $option;

        return $chunkSize >= 1 && $chunkSize <= 1000 ? $chunkSize : null;
    }

    /** @return list<string>|null */
    private function tenantIds(): ?array
    {
        $option = $this->option('tenant');

        if (! is_array($option)) {
            return null;
        }

        $tenantIds = [];

        foreach ($option as $tenantId) {
            if (! is_string($tenantId) || ! Str::isUuid($tenantId, 7)) {
                return null;
            }

            $tenantIds[] = $tenantId;
        }

        return array_values(array_unique($tenantIds));
    }

    /** @param list<string> $tenantIds */
    private function requestedTenantsAreReady(array $tenantIds): bool
    {
        $tenants = Tenant::query()
            ->whereKey($tenantIds)
            ->get(['id', 'provisioning_status'])
            ->keyBy(fn (Tenant $tenant): string => (string) $tenant->getKey());

        $missingTenantIds = array_values(array_diff($tenantIds, $tenants->keys()->all()));

        if ($missingTenantIds !== []) {
            $this->components->error('Tenant(s) not found: '.implode(', ', $missingTenantIds));

            return false;
        }

        $nonReadyTenantIds = $tenants
            ->reject(fn (Tenant $tenant): bool => $tenant->provisioning_status === TenantProvisioningStatus::Ready)
            ->keys()
            ->all();

        if ($nonReadyTenantIds !== []) {
            $this->components->error('Tenant(s) are not ready: '.implode(', ', $nonReadyTenantIds));

            return false;
        }

        return true;
    }
}
