<?php

namespace Tests\Feature\Console\Commands;

use App\Enums\TenantProvisioningStatus;
use App\Models\Tenant;
use App\Tenancy\Contracts\TenantSchemaMigrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class UpgradeTenantSchemasTest extends TestCase
{
    private FakeTenantSchemaMigrator $migrator;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'tenancy.database.central_connection' => 'sqlite',
        ]);

        Schema::dropIfExists('tenants');
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->unique();
            $table->string('provisioning_status');
            $table->boolean('active');
            $table->string('schema_version')->nullable();
            $table->timestampsTz();
        });

        $this->migrator = new FakeTenantSchemaMigrator;
        $this->app->instance(TenantSchemaMigrator::class, $this->migrator);
    }

    public function test_upgrades_only_ready_tenants_with_outdated_schema(): void
    {
        $outdatedTenantId = '0199a5e6-7d10-7f2d-8b31-2ee0833e7f85';
        $currentTenantId = '0199a5e6-7d10-7f2d-8b31-2ee0833e7f86';
        $provisioningTenantId = '0199a5e6-7d10-7f2d-8b31-2ee0833e7f87';
        $this->insertTenant($outdatedTenantId, TenantProvisioningStatus::Ready, 'sha256:old');
        $this->insertTenant($currentTenantId, TenantProvisioningStatus::Ready, 'sha256:target');
        $this->insertTenant($provisioningTenantId, TenantProvisioningStatus::Provisioning, null);

        $command = $this->artisan('tenant-schemas:upgrade', [
            '--chunk' => '1',
            '--force' => true,
        ]);

        $command->assertSuccessful()->execute();
        $this->assertSame([$outdatedTenantId], $this->migrator->migratedTenantIds);
        $this->assertDatabaseHas('tenants', [
            'id' => $outdatedTenantId,
            'schema_version' => 'sha256:target',
        ]);
        $this->assertDatabaseHas('tenants', [
            'id' => $currentTenantId,
            'schema_version' => 'sha256:target',
        ]);
        $this->assertDatabaseHas('tenants', [
            'id' => $provisioningTenantId,
            'schema_version' => null,
        ]);
    }

    public function test_continues_after_tenant_failure_and_returns_failure(): void
    {
        $failingTenantId = '0199a5e6-7d10-7f2d-8b31-2ee0833e7f85';
        $successfulTenantId = '0199a5e6-7d10-7f2d-8b31-2ee0833e7f86';
        $this->insertTenant($failingTenantId, TenantProvisioningStatus::Ready, 'sha256:old');
        $this->insertTenant($successfulTenantId, TenantProvisioningStatus::Ready, 'sha256:old');
        $this->migrator->failingTenantIds = [$failingTenantId];

        $command = $this->artisan('tenant-schemas:upgrade', ['--force' => true]);

        $command->assertFailed()->execute();
        $this->assertSame(
            [$failingTenantId, $successfulTenantId],
            $this->migrator->migratedTenantIds,
        );
        $this->assertDatabaseHas('tenants', [
            'id' => $failingTenantId,
            'schema_version' => 'sha256:old',
        ]);
        $this->assertDatabaseHas('tenants', [
            'id' => $successfulTenantId,
            'schema_version' => 'sha256:target',
        ]);
    }

    public function test_rejects_invalid_chunk_without_migrating_tenants(): void
    {
        $tenantId = '0199a5e6-7d10-7f2d-8b31-2ee0833e7f85';
        $this->insertTenant($tenantId, TenantProvisioningStatus::Ready, 'sha256:old');

        $command = $this->artisan('tenant-schemas:upgrade', [
            '--chunk' => '0',
            '--force' => true,
        ]);

        $command->assertFailed()->execute();
        $this->assertSame([], $this->migrator->migratedTenantIds);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'schema_version' => 'sha256:old',
        ]);
    }

    public function test_rejects_invalid_tenant_identifier_without_migrating_tenants(): void
    {
        $tenantId = '0199a5e6-7d10-7f2d-8b31-2ee0833e7f85';
        $this->insertTenant($tenantId, TenantProvisioningStatus::Ready, 'sha256:old');

        $command = $this->artisan('tenant-schemas:upgrade', [
            '--tenant' => ['not-a-uuid'],
            '--force' => true,
        ]);

        $command->assertFailed()->execute();
        $this->assertSame([], $this->migrator->migratedTenantIds);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'schema_version' => 'sha256:old',
        ]);
    }

    public function test_rejects_explicit_tenant_that_is_not_ready(): void
    {
        $tenantId = '0199a5e6-7d10-7f2d-8b31-2ee0833e7f85';
        $this->insertTenant($tenantId, TenantProvisioningStatus::Provisioning, null);

        $command = $this->artisan('tenant-schemas:upgrade', [
            '--tenant' => [$tenantId],
            '--force' => true,
        ]);

        $command->assertFailed()->execute();
        $this->assertSame([], $this->migrator->migratedTenantIds);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'schema_version' => null,
        ]);
    }

    public function test_rejects_explicit_tenant_that_does_not_exist(): void
    {
        $missingTenantId = '0199a5e6-7d10-7f2d-8b31-2ee0833e7f85';

        $command = $this->artisan('tenant-schemas:upgrade', [
            '--tenant' => [$missingTenantId],
            '--force' => true,
        ]);

        $command->assertFailed()->execute();
        $this->assertSame([], $this->migrator->migratedTenantIds);
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_upgrades_only_explicitly_requested_tenant(): void
    {
        $requestedTenantId = '0199a5e6-7d10-7f2d-8b31-2ee0833e7f85';
        $otherTenantId = '0199a5e6-7d10-7f2d-8b31-2ee0833e7f86';
        $this->insertTenant($requestedTenantId, TenantProvisioningStatus::Ready, 'sha256:old');
        $this->insertTenant($otherTenantId, TenantProvisioningStatus::Ready, 'sha256:old');

        $command = $this->artisan('tenant-schemas:upgrade', [
            '--tenant' => [$requestedTenantId],
            '--force' => true,
        ]);

        $command->assertSuccessful()->execute();
        $this->assertSame([$requestedTenantId], $this->migrator->migratedTenantIds);
        $this->assertDatabaseHas('tenants', [
            'id' => $requestedTenantId,
            'schema_version' => 'sha256:target',
        ]);
        $this->assertDatabaseHas('tenants', [
            'id' => $otherTenantId,
            'schema_version' => 'sha256:old',
        ]);
    }

    private function insertTenant(
        string $tenantId,
        TenantProvisioningStatus $status,
        ?string $schemaVersion,
    ): void {
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'organization_id' => str_replace('8b31', '8b32', $tenantId),
            'provisioning_status' => $status->value,
            'active' => $status === TenantProvisioningStatus::Ready,
            'schema_version' => $schemaVersion,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

class FakeTenantSchemaMigrator implements TenantSchemaMigrator
{
    /** @var list<string> */
    public array $migratedTenantIds = [];

    /** @var list<string> */
    public array $failingTenantIds = [];

    public function targetVersion(): string
    {
        return 'sha256:target';
    }

    public function migrate(Tenant $tenant): string
    {
        $tenantId = (string) $tenant->getKey();
        $this->migratedTenantIds[] = $tenantId;

        if (in_array($tenantId, $this->failingTenantIds, true)) {
            throw new RuntimeException('Tenant database is unavailable.');
        }

        return $this->targetVersion();
    }
}
