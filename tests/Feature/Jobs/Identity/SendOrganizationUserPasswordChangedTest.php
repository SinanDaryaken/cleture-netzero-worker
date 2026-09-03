<?php

namespace Tests\Feature\Jobs\Identity;

use App\Jobs\Identity\SendOrganizationUserPasswordChanged;
use App\Mail\Identity\OrganizationUserPasswordChangedMail;
use App\Models\OrganizationUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SendOrganizationUserPasswordChangedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'identity.connection' => 'sqlite',
        ]);

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
    }

    public function test_existing_user_receives_password_changed_message_in_the_current_turkish_fallback(): void
    {
        $organizationUser = OrganizationUser::factory()->create([
            'name' => 'Ayşe Yılmaz',
            'email' => 'ayse@example.com',
        ]);
        Mail::fake();

        app()->call([new SendOrganizationUserPasswordChanged($organizationUser->id), 'handle']);

        Mail::assertSent(
            OrganizationUserPasswordChangedMail::class,
            function (OrganizationUserPasswordChangedMail $mail): bool {
                $html = $mail->render();

                return $mail->hasTo('ayse@example.com')
                    && str_contains($html, 'Parolanız değiştirildi')
                    && str_contains($html, 'Merhaba Ayşe Yılmaz,');
            },
        );
    }

    public function test_job_payload_excludes_sensitive_user_data(): void
    {
        $organizationUser = OrganizationUser::factory()->create([
            'email' => 'ayse@example.com',
            'password' => 'secret-password',
        ]);

        $serializedJob = serialize(new SendOrganizationUserPasswordChanged($organizationUser->id, 'en'));

        $this->assertStringContainsString($organizationUser->id, $serializedJob);
        $this->assertStringContainsString('en', $serializedJob);
        $this->assertStringNotContainsString($organizationUser->email, $serializedJob);
        $this->assertStringNotContainsString('secret-password', $serializedJob);
        $this->assertStringNotContainsString('reset-password', $serializedJob);
    }

    public function test_missing_user_does_not_send_password_changed_message(): void
    {
        Mail::fake();

        app()->call([
            new SendOrganizationUserPasswordChanged('01990dc0-c980-7000-8000-000000000001'),
            'handle',
        ]);

        Mail::assertNothingSent();
    }
}
