<?php

namespace Tests\Feature\ProcessingTasks;

use App\Jobs\ProcessProcessingTask;
use App\Mail\Identity\OrganizationUserEmailVerificationMail;
use App\Mail\Identity\OrganizationUserPasswordChangedMail;
use App\Mail\Identity\OrganizationUserPasswordResetMail;
use App\Models\OrganizationUser;
use App\ProcessingTasks\ProcessingTaskExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProcessingTaskExecutorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'identity.connection' => 'sqlite',
            'identity.netzero_my_url' => 'https://cleture-netzero-my.test',
            'identity.email_verification.table' => 'organization_user_email_verification_tokens',
            'identity.password_reset.broker' => 'organization_users',
            'auth.passwords.organization_users.provider' => 'organization_users',
            'auth.passwords.organization_users.connection' => 'sqlite',
            'auth.passwords.organization_users.table' => 'organization_user_password_reset_tokens',
            'auth.passwords.organization_users.expire' => 60,
            'auth.passwords.organization_users.throttle' => 0,
            'processing_tasks.connection' => 'sqlite',
            'processing_tasks.worker_id' => 'test-worker',
            'processing_tasks.lease_seconds' => 360,
            'processing_tasks.max_attempts' => 5,
            'processing_tasks.backoff_seconds' => [5, 30, 120, 300, 900],
        ]);

        foreach ([
            'processing_task_failures',
            'processing_tasks',
            'organization_user_email_verification_tokens',
            'organization_user_password_reset_tokens',
            'organization_users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createTables();
    }

    /** @return array<string, array{string, class-string<Mailable>, string}> */
    public static function localizedIdentityContracts(): array
    {
        return [
            'email verification' => [
                'organization-user.email-verification',
                OrganizationUserEmailVerificationMail::class,
                'Verify your email address',
            ],
            'password reset' => [
                'organization-user.password-reset',
                OrganizationUserPasswordResetMail::class,
                'Reset your password',
            ],
            'password changed' => [
                'organization-user.password-changed',
                OrganizationUserPasswordChangedMail::class,
                'Your password was changed',
            ],
        ];
    }

    public function test_current_dispatch_token_executes_domain_job_and_deletes_task_after_success(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask(
            'organization-user.password-reset',
            ['organizationUserId' => $organizationUser->id],
            $dispatchToken,
        );
        Mail::fake();

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertDatabaseCount('processing_task_failures', 0);
        Mail::assertSent(
            OrganizationUserPasswordResetMail::class,
            fn (OrganizationUserPasswordResetMail $mail): bool => $mail->hasTo($organizationUser->email),
        );
    }

    public function test_password_changed_contract_executes_confirmation_job_and_deletes_task_after_success(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask(
            'organization-user.password-changed',
            ['organizationUserId' => $organizationUser->id],
            $dispatchToken,
        );
        Mail::fake();

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertDatabaseCount('processing_task_failures', 0);
        Mail::assertSent(
            OrganizationUserPasswordChangedMail::class,
            fn (OrganizationUserPasswordChangedMail $mail): bool => $mail->hasTo($organizationUser->email),
        );
    }

    /** @param class-string<Mailable> $mailableClass */
    #[DataProvider('localizedIdentityContracts')]
    public function test_v2_contract_sends_localized_mail_and_deletes_task_after_success(
        string $type,
        string $mailableClass,
        string $expectedTitle,
    ): void {
        $organizationUser = OrganizationUser::factory()->create();
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask(
            $type,
            [
                'locale' => 'en',
                'organizationUserId' => $organizationUser->id,
            ],
            $dispatchToken,
            payloadVersion: 2,
        );
        Mail::fake();

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertDatabaseCount('processing_task_failures', 0);
        Mail::assertSent(
            $mailableClass,
            fn (Mailable $mail): bool => $mail->hasTo($organizationUser->email)
                && str_contains($mail->render(), $expectedTitle),
        );
    }

    public function test_v2_contract_archives_invalid_locale_without_sending_mail(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask(
            'organization-user.email-verification',
            [
                'organizationUserId' => $organizationUser->id,
                'locale' => 'de',
            ],
            $dispatchToken,
            payloadVersion: 2,
        );
        Mail::fake();

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertDatabaseHas('processing_task_failures', [
            'task_id' => $taskId,
            'payload' => '{}',
            'error_code' => 'invalid_payload',
        ]);
        $this->assertDatabaseCount('organization_user_email_verification_tokens', 0);
        Mail::assertNothingSent();
    }

    public function test_v2_contract_archives_payload_without_locale_without_sending_mail(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask(
            'organization-user.email-verification',
            ['organizationUserId' => $organizationUser->id],
            $dispatchToken,
            payloadVersion: 2,
        );
        Mail::fake();

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertDatabaseHas('processing_task_failures', [
            'task_id' => $taskId,
            'payload' => '{}',
            'error_code' => 'invalid_payload',
        ]);
        $this->assertDatabaseCount('organization_user_email_verification_tokens', 0);
        Mail::assertNothingSent();
    }

    public function test_stale_dispatch_token_cannot_execute_or_change_task_state(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        $currentDispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask(
            'organization-user.password-reset',
            ['organizationUserId' => $organizationUser->id],
            $currentDispatchToken,
        );
        Mail::fake();

        app(ProcessingTaskExecutor::class)->execute($taskId, (string) Str::uuid7());

        $this->assertDatabaseHas('processing_tasks', [
            'id' => $taskId,
            'status' => 'queued',
            'attempts' => 0,
            'dispatch_token' => $currentDispatchToken,
        ]);
        $this->assertDatabaseCount('processing_task_failures', 0);
        Mail::assertNothingSent();
    }

    public function test_invalid_payload_is_archived_without_copying_secret_data(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask(
            'organization-user.email-verification',
            [
                'organizationUserId' => $organizationUser->id,
                'password' => 'should-never-be-copied',
            ],
            $dispatchToken,
            dedupeKey: 'secret-dedupe-value',
        );

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $failure = DB::table('processing_task_failures')->where('task_id', $taskId)->first();
        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertSame('{}', $failure->payload);
        $this->assertSame('invalid_payload', $failure->error_code);
        $this->assertStringStartsWith('sha256:', $failure->dedupe_key);
        $this->assertStringNotContainsString(
            'should-never-be-copied',
            json_encode($failure, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString('secret-dedupe-value', $failure->dedupe_key);
    }

    public function test_password_changed_contract_rejects_sensitive_payload_without_copying_it_to_failure_snapshot(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask(
            'organization-user.password-changed',
            [
                'organizationUserId' => $organizationUser->id,
                'locale' => 'en',
                'email' => 'private@example.com',
                'password' => 'private-password',
                'resetToken' => 'private-reset-token',
                'resetUrl' => 'https://example.com/reset/private-reset-token',
            ],
            $dispatchToken,
            dedupeKey: 'password-changed:private-dedupe-value',
            payloadVersion: 2,
        );

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $failure = DB::table('processing_task_failures')->where('task_id', $taskId)->first();
        $serializedFailure = json_encode($failure, JSON_THROW_ON_ERROR);
        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertSame('{}', $failure->payload);
        $this->assertSame('invalid_payload', $failure->error_code);
        $this->assertStringStartsWith('sha256:', $failure->dedupe_key);
        $this->assertStringNotContainsString('private@example.com', $serializedFailure);
        $this->assertStringNotContainsString('private-password', $serializedFailure);
        $this->assertStringNotContainsString('private-reset-token', $serializedFailure);
        $this->assertStringNotContainsString('private-dedupe-value', $serializedFailure);
    }

    public function test_v2_terminal_failure_snapshot_contains_only_validated_contract_fields(): void
    {
        $organizationUser = OrganizationUser::factory()->create([
            'email' => 'private@example.com',
        ]);
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask(
            'organization-user.password-changed',
            [
                'organizationUserId' => $organizationUser->id,
                'locale' => 'en',
            ],
            $dispatchToken,
            attempts: 4,
            payloadVersion: 2,
        );
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('mail transport unavailable'));

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $failure = DB::table('processing_task_failures')->where('task_id', $taskId)->first();
        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertSame(
            [
                'organizationUserId' => $organizationUser->id,
                'locale' => 'en',
            ],
            json_decode($failure->payload, true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            'private@example.com',
            json_encode($failure, JSON_THROW_ON_ERROR),
        );
    }

    public function test_retryable_failure_returns_task_to_pending_with_domain_backoff(): void
    {
        $this->travelTo('2026-09-03 10:00:00');
        $organizationUser = OrganizationUser::factory()->create();
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask(
            'organization-user.password-reset',
            ['organizationUserId' => $organizationUser->id],
            $dispatchToken,
        );
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('mail transport unavailable'));

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $this->assertDatabaseHas('processing_tasks', [
            'id' => $taskId,
            'status' => 'pending',
            'attempts' => 1,
            'dispatched_at' => null,
            'dispatch_token' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'claimed_by' => null,
        ]);
        $this->assertSame(
            '2026-09-03 10:00:05',
            DB::table('processing_tasks')->where('id', $taskId)->value('available_at'),
        );
        $this->assertDatabaseCount('processing_task_failures', 0);
    }

    public function test_final_retryable_failure_is_archived_as_the_terminal_domain_result(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask(
            'organization-user.password-reset',
            ['organizationUserId' => $organizationUser->id],
            $dispatchToken,
            attempts: 4,
        );
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('mail transport unavailable'));

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        $this->assertDatabaseHas('processing_task_failures', [
            'task_id' => $taskId,
            'attempts' => 5,
            'error_code' => 'execution_attempts_exhausted',
        ]);
    }

    public function test_expired_execution_can_be_reclaimed_by_the_same_transport_job(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask(
            'organization-user.password-reset',
            ['organizationUserId' => $organizationUser->id],
            $dispatchToken,
            status: 'processing',
            attempts: 1,
            leaseExpiresAt: now()->subSecond(),
        );
        Mail::fake();

        app(ProcessingTaskExecutor::class)->execute($taskId, $dispatchToken);

        $this->assertDatabaseMissing('processing_tasks', ['id' => $taskId]);
        Mail::assertSent(OrganizationUserPasswordResetMail::class);
    }

    public function test_exhausted_transport_releases_unstarted_task_for_redispatch(): void
    {
        $dispatchToken = (string) Str::uuid7();
        $taskId = $this->insertQueuedTask(
            'organization-user.password-reset',
            ['organizationUserId' => (string) Str::uuid7()],
            $dispatchToken,
        );
        $job = new ProcessProcessingTask($taskId, $dispatchToken);

        $job->failed(new RuntimeException('worker terminated'));

        $this->assertDatabaseHas('processing_tasks', [
            'id' => $taskId,
            'status' => 'pending',
            'attempts' => 0,
            'dispatch_token' => null,
        ]);
        $this->assertDatabaseCount('processing_task_failures', 0);
    }

    /** @param array<string, string> $payload */
    private function insertQueuedTask(
        string $type,
        array $payload,
        string $dispatchToken,
        string $dedupeKey = 'organization-user:test',
        string $status = 'queued',
        int $attempts = 0,
        mixed $leaseExpiresAt = null,
        int $payloadVersion = 1,
    ): string {
        $taskId = (string) Str::uuid7();

        DB::table('processing_tasks')->insert([
            'id' => $taskId,
            'type' => $type,
            'payload_version' => $payloadVersion,
            'tenant_id' => null,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'dedupe_key' => $dedupeKey,
            'status' => $status,
            'available_at' => now(),
            'attempts' => $attempts,
            'dispatched_at' => now()->subMinute(),
            'dispatch_token' => $dispatchToken,
            'claimed_at' => $status === 'processing' ? now()->subMinutes(2) : null,
            'lease_expires_at' => $leaseExpiresAt,
            'claimed_by' => $status === 'processing' ? 'previous-worker' : null,
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
        Schema::create('organization_user_email_verification_tokens', function (Blueprint $table): void {
            $table->uuid('organization_user_id')->primary();
            $table->string('token');
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');
        });
        Schema::create('organization_user_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
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
