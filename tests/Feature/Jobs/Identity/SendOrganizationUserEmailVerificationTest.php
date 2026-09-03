<?php

namespace Tests\Feature\Jobs\Identity;

use App\Jobs\Identity\SendOrganizationUserEmailVerification;
use App\Mail\Identity\OrganizationUserEmailVerificationMail;
use App\Models\OrganizationUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SendOrganizationUserEmailVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'identity.connection' => 'sqlite',
            'identity.netzero_my_url' => 'https://cleture-netzero-my.test',
            'identity.email_verification.table' => 'organization_user_email_verification_tokens',
        ]);

        Schema::dropIfExists('organization_user_email_verification_tokens');
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
        Schema::create('organization_user_email_verification_tokens', function (Blueprint $table): void {
            $table->uuid('organization_user_id')->primary();
            $table->string('token');
            $table->timestampTz('created_at');
            $table->timestampTz('expires_at');
        });
    }

    public function test_unverified_user_receives_link_while_database_stores_only_token_hash(): void
    {
        $organizationUser = OrganizationUser::factory()->create();
        Mail::fake();

        app()->call([new SendOrganizationUserEmailVerification($organizationUser->id), 'handle']);

        $plainToken = null;
        Mail::assertSent(
            OrganizationUserEmailVerificationMail::class,
            function (OrganizationUserEmailVerificationMail $mail) use ($organizationUser, &$plainToken): bool {
                $segments = explode('/', trim((string) parse_url($mail->actionUrl, PHP_URL_PATH), '/'));
                $plainToken = end($segments);

                return $mail->hasTo($organizationUser->email)
                    && str_contains($mail->render(), 'E-posta adresinizi doğrulayın')
                    && $segments[count($segments) - 2] === $organizationUser->id;
            },
        );

        $storedToken = DB::table('organization_user_email_verification_tokens')
            ->where('organization_user_id', $organizationUser->id)
            ->value('token');

        $this->assertIsString($plainToken);
        $this->assertIsString($storedToken);
        $this->assertNotSame($plainToken, $storedToken);
        $this->assertTrue(Hash::check($plainToken, $storedToken));
    }

    public function test_verified_user_does_not_receive_another_verification_message(): void
    {
        $organizationUser = OrganizationUser::factory()->verified()->create();
        Mail::fake();

        app()->call([new SendOrganizationUserEmailVerification($organizationUser->id), 'handle']);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('organization_user_email_verification_tokens', 0);
    }
}
