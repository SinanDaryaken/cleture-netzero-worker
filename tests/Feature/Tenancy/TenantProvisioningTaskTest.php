<?php

namespace Tests\Feature\Tenancy;

use App\Enums\TenantProvisioningStatus;
use App\Models\Tenant;
use App\ProcessingTasks\ProcessingTaskExecutor;
use App\Tenancy\Contracts\TenantDatabaseProvisioner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class TenantProvisioningTaskTest extends TestCase
{
    private FakeTenantDatabaseProvisioner $provisioner;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'processing_tasks.connection' => 'sqlite',
            'processing_tasks.worker_id' => 'test-worker',
            'tenancy.database.central_connection' => 'sqlite',
        ]);

        foreach (['processing_task_failures', 'processing_tasks', 'tenants'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createTables();
        $this->provisioner = new FakeTenantDatabaseProvisioner;
        $this->app->instance(TenantDatabaseProvisioner::class, $this->provisioner);
    }

    public function test_successful_provisioning_activates_tenant_and_deletes_canonical_task(): void
    {
        $tenantId = $this->insertTenant();
        [$taskId, $dispatchToken] = $this->insertQueuedTask($tenantId);

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $this->assertSame([$tenantId], $this->provisioner->provisionedTenantIds);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'provisioning_status' => TenantProvisioningStatus::Ready->value,
            'active' => true,
            'schema_version' => 'sha256:test-schema',
        ]);
        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertDatabaseCount('processing_task_failures', 0);
    }

    public function test_recoverable_failure_returns_task_to_pending_and_keeps_tenant_provisioning(): void
    {
        $tenantId = $this->insertTenant();
        [$taskId, $dispatchToken] = $this->insertQueuedTask($tenantId);
        $this->provisioner->shouldFail = true;

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'provisioning_status' => TenantProvisioningStatus::Provisioning->value,
            'active' => false,
        ]);
        $this->assertDatabaseHas('processing_tasks', [
            'id' => $taskId,
            'status' => 'pending',
            'attempts' => 1,
            'dispatch_token' => null,
        ]);
        $this->assertDatabaseCount('processing_task_failures', 0);
    }

    public function test_exhausted_failure_marks_tenant_failed_and_archives_safe_snapshot(): void
    {
        $tenantId = $this->insertTenant();
        [$taskId, $dispatchToken] = $this->insertQueuedTask($tenantId, attempts: 4);
        $this->provisioner->shouldFail = true;

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'provisioning_status' => TenantProvisioningStatus::Failed->value,
            'active' => false,
        ]);
        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertDatabaseHas('processing_task_failures', [
            'task_id' => $taskId,
            'tenant_id' => $tenantId,
            'payload' => '{}',
            'attempts' => 5,
            'error_code' => 'execution_attempts_exhausted',
        ]);
    }

    public function test_payload_data_is_rejected_without_reaching_provisioner(): void
    {
        $tenantId = $this->insertTenant();
        [$taskId, $dispatchToken] = $this->insertQueuedTask($tenantId, [
            'databasePassword' => 'must-not-leave-the-producer',
        ]);

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $failure = DB::table('processing_task_failures')->where('task_id', $taskId)->sole();
        $this->assertSame([], $this->provisioner->provisionedTenantIds);
        $this->assertSame('{}', $failure->payload);
        $this->assertSame('invalid_payload', $failure->error_code);
        $this->assertStringNotContainsString(
            'must-not-leave-the-producer',
            json_encode($failure, JSON_THROW_ON_ERROR),
        );
        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'provisioning_status' => TenantProvisioningStatus::Failed->value,
            'active' => false,
        ]);
    }

    private function insertTenant(): string
    {
        $tenantId = (string) Str::uuid7();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'organization_id' => (string) Str::uuid7(),
            'provisioning_status' => TenantProvisioningStatus::Pending->value,
            'active' => false,
            'schema_version' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    /** @param array<string, string> $payload */
    private function insertQueuedTask(
        string $tenantId,
        array $payload = [],
        int $attempts = 0,
    ): array {
        $taskId = (string) Str::uuid7();
        $dispatchToken = (string) Str::uuid7();

        DB::table('processing_tasks')->insert([
            'id' => $taskId,
            'type' => 'tenant.provision',
            'payload_version' => 1,
            'tenant_id' => $tenantId,
            'payload' => json_encode((object) $payload, JSON_THROW_ON_ERROR),
            'dedupe_key' => "tenant:{$tenantId}:provision",
            'status' => 'queued',
            'available_at' => now(),
            'attempts' => $attempts,
            'dispatched_at' => now(),
            'dispatch_token' => $dispatchToken,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'claimed_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$taskId, $dispatchToken];
    }

    private function createTables(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->unique();
            $table->string('provisioning_status');
            $table->boolean('active');
            $table->string('schema_version')->nullable();
            $table->timestampsTz();
        });
        Schema::create('processing_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->unsignedInteger('payload_version');
            $table->uuid('tenant_id')->nullable();
            $table->text('payload');
            $table->string('dedupe_key')->unique();
            $table->string('status');
            $table->timestampTz('available_at');
            $table->unsignedInteger('attempts');
            $table->timestampTz('dispatched_at')->nullable();
            $table->uuid('dispatch_token')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->string('claimed_by')->nullable();
            $table->timestampsTz();
        });
        Schema::create('processing_task_failures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('task_id')->unique();
            $table->string('type');
            $table->unsignedInteger('payload_version');
            $table->uuid('tenant_id')->nullable();
            $table->text('payload');
            $table->string('dedupe_key');
            $table->unsignedInteger('attempts');
            $table->string('error_code');
            $table->text('error_summary');
            $table->timestampTz('failed_at');
        });
    }
}

class FakeTenantDatabaseProvisioner implements TenantDatabaseProvisioner
{
    /** @var list<string> */
    public array $provisionedTenantIds = [];

    public bool $shouldFail = false;

    public function provision(Tenant $tenant): string
    {
        $this->provisionedTenantIds[] = (string) $tenant->getKey();

        if ($this->shouldFail) {
            throw new RuntimeException('Tenant database is unavailable.');
        }

        return 'sha256:test-schema';
    }
}
