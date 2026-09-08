<?php

namespace Tests\Feature\ProcessingTasks;

use App\Jobs\ProcessProcessingTask;
use App\Models\OrganizationUser;
use App\ProcessingTasks\ProcessingTaskConsumer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProcessingTaskConsumerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'identity.connection' => 'sqlite',
            'processing_tasks.connection' => 'sqlite',
            'processing_tasks.queue_connection' => 'database',
            'processing_tasks.worker_id' => 'test-worker',
            'processing_tasks.lease_seconds' => 360,
            'processing_tasks.max_attempts' => 5,
            'processing_tasks.backoff_seconds' => [5, 30, 120, 300, 900],
            'processing_tasks.dispatch.stale_after_seconds' => 600,
            'queue.connections.database.connection' => 'sqlite',
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.queue' => 'identity-mails',
            'queue.connections.database.after_commit' => false,
        ]);

        foreach (['jobs', 'processing_task_failures', 'processing_tasks', 'tenants', 'organization_users'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createTables();
    }

    /** @return array<string, array{string, int, string|null}> */
    public static function supportedTasks(): array
    {
        return [
            'email verification v1' => ['organization-user.email-verification', 1, null],
            'email verification v2' => ['organization-user.email-verification', 2, 'en'],
            'password reset v1' => ['organization-user.password-reset', 1, null],
            'password reset v2' => ['organization-user.password-reset', 2, 'tr'],
            'password changed v1' => ['organization-user.password-changed', 1, null],
            'password changed v2' => ['organization-user.password-changed', 2, 'en'],
        ];
    }

    /** @return array<string, array{string, int}> */
    public static function unsupportedContracts(): array
    {
        return [
            'unknown type' => ['organization-user.unknown', 1],
            'unknown payload version' => ['organization-user.email-verification', 3],
            'retired candidate ingest v1' => ['emission.candidate.ingest', 1],
            'retired candidate ingest v2' => ['emission.candidate.ingest', 2],
        ];
    }

    #[DataProvider('supportedTasks')]
    public function test_supported_pending_task_is_atomically_queued_with_a_dispatch_token(
        string $type,
        int $payloadVersion,
        ?string $locale,
    ): void {
        $organizationUser = OrganizationUser::factory()->create();
        $payload = [
            'organizationUserId' => $organizationUser->id,
        ];

        if ($locale !== null) {
            $payload['locale'] = $locale;
        }

        $taskId = $this->insertTask(
            $type,
            $payload,
            dedupeKey: 'private-dedupe-value',
            payloadVersion: $payloadVersion,
        );

        $result = app(ProcessingTaskConsumer::class)->consume(10);

        $task = DB::table('processing_tasks')->where('id', $taskId)->first();
        $this->assertSame(1, $result->claimed);
        $this->assertSame(1, $result->enqueued);
        $this->assertSame('queued', $task->status);
        $this->assertSame(0, $task->attempts);
        $this->assertTrue(Str::isUuid($task->dispatch_token));
        $this->assertNotNull($task->dispatched_at);
        $this->assertNull($task->claimed_at);
        $this->assertNull($task->lease_expires_at);
        $this->assertNull($task->claimed_by);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('identity-mails', DB::table('jobs')->value('queue'));

        $queuedPayload = json_decode(
            (string) DB::table('jobs')->value('payload'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $queuedJob = unserialize($queuedPayload['data']['command']);

        $this->assertInstanceOf(ProcessProcessingTask::class, $queuedJob);
        $this->assertSame($taskId, $queuedJob->processingTaskId);
        $this->assertSame($task->dispatch_token, $queuedJob->dispatchToken);
        $this->assertStringNotContainsString($organizationUser->id, $queuedPayload['data']['command']);
        $this->assertStringNotContainsString('private-dedupe-value', $queuedPayload['data']['command']);
    }

    #[DataProvider('unsupportedContracts')]
    public function test_unsupported_contract_remains_pending_and_is_not_dispatched(
        string $type,
        int $payloadVersion,
    ): void {
        $taskId = $this->insertTask(
            $type,
            ['token' => 'should-never-reach-the-queue'],
            payloadVersion: $payloadVersion,
        );

        $result = app(ProcessingTaskConsumer::class)->consume(10);

        $this->assertSame(0, $result->claimed);
        $this->assertSame(0, $result->enqueued);
        $this->assertDatabaseHas('processing_tasks', [
            'id' => $taskId,
            'status' => 'pending',
            'dispatch_token' => null,
        ]);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('processing_task_failures', 0);
    }

    public function test_stale_queued_task_is_redispatched_with_a_new_fencing_token(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        $oldDispatchToken = (string) Str::uuid7();
        $taskId = $this->insertTask(
            'organization-user.password-reset',
            ['organizationUserId' => $organizationUser->id],
            status: 'queued',
            dispatchToken: $oldDispatchToken,
            dispatchedAt: now()->subMinutes(11),
        );

        app(ProcessingTaskConsumer::class)->consume(10);

        $task = DB::table('processing_tasks')->where('id', $taskId)->first();
        $this->assertSame('queued', $task->status);
        $this->assertNotSame($oldDispatchToken, $task->dispatch_token);
        $this->assertTrue(Str::isUuid($task->dispatch_token));
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_pending_tenant_provisioning_task_is_routed_to_its_own_queue(): void
    {
        $tenantId = (string) Str::uuid7();
        $taskId = $this->insertTask(
            'tenant.provision',
            [],
            dedupeKey: "tenant:{$tenantId}:provision",
            tenantId: $tenantId,
        );

        $result = app(ProcessingTaskConsumer::class)->consume(10);

        $this->assertSame(1, $result->claimed);
        $this->assertSame(1, $result->enqueued);
        $this->assertDatabaseHas('processing_tasks', [
            'id' => $taskId,
            'tenant_id' => $tenantId,
            'status' => 'queued',
        ]);
        $this->assertSame('tenant-provisioning', DB::table('jobs')->value('queue'));
    }

    public function test_retired_candidate_task_is_preserved_without_dispatch(): void
    {
        $packageId = (string) Str::uuid7();
        $artifactSha256 = str_repeat('a', 64);
        $taskId = $this->insertTask(
            'emission.candidate.ingest',
            [
                'package_id' => $packageId,
                'storage_profile' => 'atlas.candidate_ingress',
                'object_key' => "sha256/{$artifactSha256}.zip",
                'object_version_id' => 'object-version-1',
                'expected_sha256' => $artifactSha256,
            ],
            dedupeKey: "emission-candidate:{$packageId}:ingest",
        );

        $result = app(ProcessingTaskConsumer::class)->consume(10);

        $this->assertSame(0, $result->claimed);
        $this->assertSame(0, $result->enqueued);
        $this->assertDatabaseHas('processing_tasks', [
            'id' => $taskId,
            'tenant_id' => null,
            'status' => 'pending',
        ]);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('processing_task_failures', 0);
    }

    public function test_expired_processing_task_is_redispatched_without_incrementing_domain_attempts(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        $oldDispatchToken = (string) Str::uuid7();
        $taskId = $this->insertTask(
            'organization-user.password-reset',
            ['organizationUserId' => $organizationUser->id],
            status: 'processing',
            attempts: 1,
            leaseExpiresAt: now()->subSecond(),
            dispatchToken: $oldDispatchToken,
            dispatchedAt: now()->subMinutes(2),
        );

        app(ProcessingTaskConsumer::class)->consume(10);

        $task = DB::table('processing_tasks')->where('id', $taskId)->first();
        $this->assertSame('queued', $task->status);
        $this->assertSame(1, $task->attempts);
        $this->assertNotSame($oldDispatchToken, $task->dispatch_token);
        $this->assertNull($task->claimed_at);
        $this->assertNull($task->lease_expires_at);
        $this->assertNull($task->claimed_by);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_expired_processing_task_after_maximum_attempts_is_archived(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        $taskId = $this->insertTask(
            'organization-user.email-verification',
            ['organizationUserId' => $organizationUser->id],
            status: 'processing',
            attempts: 5,
            leaseExpiresAt: now()->subSecond(),
            dispatchToken: (string) Str::uuid7(),
            dispatchedAt: now()->subMinutes(2),
        );

        $result = app(ProcessingTaskConsumer::class)->consume(10);

        $this->assertSame(1, $result->failed);
        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertDatabaseHas('processing_task_failures', [
            'task_id' => $taskId,
            'attempts' => 5,
            'error_code' => 'lease_attempts_exhausted',
        ]);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_expired_tenant_task_after_maximum_attempts_marks_tenant_failed(): void
    {
        $tenantId = (string) Str::uuid7();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'organization_id' => (string) Str::uuid7(),
            'provisioning_status' => 'provisioning',
            'active' => false,
            'schema_version' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $taskId = $this->insertTask(
            'tenant.provision',
            [],
            dedupeKey: "tenant:{$tenantId}:provision",
            status: 'processing',
            attempts: 5,
            leaseExpiresAt: now()->subSecond(),
            dispatchToken: (string) Str::uuid7(),
            dispatchedAt: now()->subMinutes(2),
            tenantId: $tenantId,
        );

        $result = app(ProcessingTaskConsumer::class)->consume(10);

        $this->assertSame(1, $result->failed);
        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertDatabaseHas('tenants', [
            'id' => $tenantId,
            'provisioning_status' => 'failed',
            'active' => false,
        ]);
    }

    public function test_queue_write_failure_rolls_back_the_queued_state(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        $taskId = $this->insertTask('organization-user.password-reset', [
            'organizationUserId' => $organizationUser->id,
        ]);
        Schema::drop('jobs');

        $result = app(ProcessingTaskConsumer::class)->consume(10);

        $this->assertSame(1, $result->retried);
        $this->assertDatabaseHas('processing_tasks', [
            'id' => $taskId,
            'status' => 'pending',
            'attempts' => 0,
            'dispatched_at' => null,
            'dispatch_token' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'claimed_by' => null,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function insertTask(
        string $type,
        array $payload,
        string $dedupeKey = 'organization-user:test',
        string $status = 'pending',
        int $attempts = 0,
        mixed $leaseExpiresAt = null,
        int $payloadVersion = 1,
        ?string $dispatchToken = null,
        mixed $dispatchedAt = null,
        ?string $tenantId = null,
    ): string {
        $taskId = (string) Str::uuid7();
        $claimedAt = $status === 'processing' ? now()->subMinute() : null;

        DB::table('processing_tasks')->insert([
            'id' => $taskId,
            'type' => $type,
            'payload_version' => $payloadVersion,
            'tenant_id' => $tenantId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'dedupe_key' => $dedupeKey,
            'status' => $status,
            'available_at' => now(),
            'attempts' => $attempts,
            'dispatched_at' => $dispatchedAt,
            'dispatch_token' => $dispatchToken,
            'claimed_at' => $claimedAt,
            'lease_expires_at' => $leaseExpiresAt,
            'claimed_by' => $status === 'processing' ? 'stale-worker' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $taskId;
    }

    private function createTables(): void
    {
        Schema::create('organization_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('auth_version')->default(1);
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
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->unique();
            $table->string('provisioning_status');
            $table->boolean('active');
            $table->string('schema_version')->nullable();
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
        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }
}
