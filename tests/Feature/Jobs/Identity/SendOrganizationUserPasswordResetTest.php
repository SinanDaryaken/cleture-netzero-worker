<?php

namespace Tests\Feature\Jobs\Identity;

use App\Jobs\Identity\SendOrganizationUserPasswordReset;
use App\Mail\Identity\OrganizationUserPasswordResetMail;
use App\Models\OrganizationUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SendOrganizationUserPasswordResetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'identity.connection' => 'sqlite',
            'identity.netzero_my_url' => 'https://cleture-netzero-my.test',
            'identity.password_reset.broker' => 'organization_users',
            'auth.passwords.organization_users.provider' => 'organization_users',
            'auth.passwords.organization_users.connection' => 'sqlite',
            'auth.passwords.organization_users.table' => 'organization_user_password_reset_tokens',
            'auth.passwords.organization_users.expire' => 60,
            'auth.passwords.organization_users.throttle' => 60,
        ]);

        Schema::dropIfExists('organization_user_password_reset_tokens');
        Schema::dropIfExists('organization_users');
        Schema::create('organization_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('auth_version')->default(1);
            $table->timestampsTz();
        });
        Schema::create('organization_user_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function test_active_cooldown_does_not_create_another_reset_token_or_send_mail(): void
    {
        $this->travelTo('2026-09-03 10:00:00');
        $organizationUser = OrganizationUser::factory()->create();
        Password::broker('organization_users')->createToken($organizationUser);
        $storedTokenBefore = DB::table('organization_user_password_reset_tokens')
            ->where('email', $organizationUser->email)
            ->value('token');
        Mail::fake();

        app()->call([new SendOrganizationUserPasswordReset($organizationUser->id, 'en'), 'handle']);

        $this->assertSame(
            $storedTokenBefore,
            DB::table('organization_user_password_reset_tokens')
                ->where('email', $organizationUser->email)
                ->value('token'),
        );
        $this->assertDatabaseCount('organization_user_password_reset_tokens', 1);
        Mail::assertNothingSent();
    }

    public function test_expired_cooldown_creates_a_new_reset_token_and_sends_mail(): void
    {
        $this->travelTo('2026-09-03 10:00:00');
        $organizationUser = OrganizationUser::factory()->create();
        Password::broker('organization_users')->createToken($organizationUser);
        $storedTokenBefore = DB::table('organization_user_password_reset_tokens')
            ->where('email', $organizationUser->email)
            ->value('token');
        $this->travel(61)->seconds();
        Mail::fake();

        app()->call([new SendOrganizationUserPasswordReset($organizationUser->id, 'en'), 'handle']);

        $plainToken = null;
        Mail::assertSent(
            OrganizationUserPasswordResetMail::class,
            function (OrganizationUserPasswordResetMail $mail) use ($organizationUser, &$plainToken): bool {
                parse_str((string) parse_url($mail->actionUrl, PHP_URL_QUERY), $query);
                $segments = explode('/', trim((string) parse_url($mail->actionUrl, PHP_URL_PATH), '/'));
                $plainToken = end($segments);

                return $mail->hasTo($organizationUser->email)
                    && str_contains($mail->render(), 'Reset your password')
                    && ($query['email'] ?? null) === $organizationUser->email;
            },
        );

        $storedTokenAfter = DB::table('organization_user_password_reset_tokens')
            ->where('email', $organizationUser->email)
            ->value('token');

        $this->assertIsString($plainToken);
        $this->assertIsString($storedTokenBefore);
        $this->assertIsString($storedTokenAfter);
        $this->assertNotSame($storedTokenBefore, $storedTokenAfter);
        $this->assertNotSame($plainToken, $storedTokenAfter);
        $this->assertTrue(Hash::check($plainToken, $storedTokenAfter));
    }

    public function test_missing_user_does_not_send_mail_or_create_a_token(): void
    {
        Mail::fake();

        app()->call([
            new SendOrganizationUserPasswordReset('01990dc0-c980-7000-8000-000000000001'),
            'handle',
        ]);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('organization_user_password_reset_tokens', 0);
    }
}
