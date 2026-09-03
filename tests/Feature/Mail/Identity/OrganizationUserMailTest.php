<?php

namespace Tests\Feature\Mail\Identity;

use App\Mail\Identity\OrganizationUserEmailVerificationMail;
use App\Mail\Identity\OrganizationUserPasswordChangedMail;
use App\Mail\Identity\OrganizationUserPasswordResetMail;
use Illuminate\Mail\Mailable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationUserMailTest extends TestCase
{
    public function test_email_verification_message_renders_branded_html_with_verification_action(): void
    {
        $actionUrl = 'https://cleture-netzero-my.test/email/verify/01990dc0-c980-7000-8000-000000000001/plain-token';
        $mail = new OrganizationUserEmailVerificationMail('Ayşe Yılmaz', $actionUrl);

        $html = $mail->render();

        $this->assertStringContainsString('NET ZERO', $html);
        $this->assertStringContainsString('Cleture', $html);
        $this->assertStringContainsString('Sürdürülebilir yarınlar burada başlıyor.', $html);
        $this->assertStringContainsString('E-posta adresinizi doğrulayın', $html);
        $this->assertStringContainsString('E-posta adresimi doğrula', $html);
        $this->assertStringContainsString('class="button-link" href="'.$actionUrl.'"', $html);
        $this->assertStringContainsString('bu bağlantıyı kimseyle paylaşmayın', $html);
    }

    public function test_password_reset_message_renders_branded_html_with_reset_action(): void
    {
        $actionUrl = 'https://cleture-netzero-my.test/reset-password/plain-token?email=mehmet%40example.com';
        $mail = new OrganizationUserPasswordResetMail('Mehmet Yılmaz', $actionUrl);

        $html = $mail->render();

        $this->assertStringContainsString('NET ZERO', $html);
        $this->assertStringContainsString('Cleture', $html);
        $this->assertStringContainsString('Sürdürülebilir yarınlar burada başlıyor.', $html);
        $this->assertStringContainsString('Parolanızı yenileyin', $html);
        $this->assertStringContainsString('Parolamı yenile', $html);
        $this->assertStringContainsString('class="button-link" href="'.$actionUrl.'"', $html);
        $this->assertStringContainsString('herhangi bir işlem yapmanız gerekmez', $html);
    }

    public function test_password_changed_message_renders_current_turkish_fallback_without_sensitive_data(): void
    {
        $mail = new OrganizationUserPasswordChangedMail('Ayşe Yılmaz');

        $html = $mail->render();

        $this->assertStringContainsString('NET ZERO', $html);
        $this->assertStringContainsString('Parolanız değiştirildi', $html);
        $this->assertStringContainsString('Merhaba Ayşe Yılmaz,', $html);
        $this->assertStringContainsString('başka bir işlem yapmanız gerekmez', $html);
        $this->assertStringNotContainsString('href=', $html);
        $this->assertStringNotContainsString('reset-password', $html);
        $this->assertStringNotContainsString('plain-token', $html);
        $mail->assertSeeInText('Cleture Net Zero hesabınızın parolası başarıyla değiştirildi.');
    }

    public function test_password_changed_message_escapes_organization_user_name_in_html(): void
    {
        $dangerousName = 'Ayşe <script>alert("xss")</script>';
        $mail = new OrganizationUserPasswordChangedMail($dangerousName);

        $html = $mail->render();

        $this->assertStringContainsString('Ayşe &lt;script&gt;', $html);
        $this->assertStringNotContainsString($dangerousName, $html);
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
    }

    /**
     * @param  class-string<Mailable>  $mailClass
     * @param  list<string>  $constructorArguments
     */
    #[DataProvider('localizedIdentityMessages')]
    public function test_identity_messages_render_in_the_requested_english_locale(
        string $mailClass,
        array $constructorArguments,
        string $expectedSubject,
        string $expectedTitle,
        string $expectedText,
    ): void {
        $mail = (new $mailClass(...$constructorArguments))->locale('en');

        $mail->assertHasSubject($expectedSubject);
        $mail->assertSeeInHtml($expectedTitle);
        $mail->assertSeeInText($expectedText);
    }

    public function test_rendering_an_english_message_does_not_leak_locale_into_the_turkish_fallback(): void
    {
        $englishMail = (new OrganizationUserPasswordChangedMail('Ayşe Yılmaz'))->locale('en');
        $turkishMail = new OrganizationUserPasswordChangedMail('Ayşe Yılmaz');

        $englishMail->assertSeeInHtml('Your password was changed');
        $turkishMail->assertSeeInHtml('Parolanız değiştirildi');
        $turkishMail->assertDontSeeInHtml('Your password was changed');
    }

    /**
     * @param  class-string<Mailable>  $mailClass
     */
    #[DataProvider('identityMessages')]
    public function test_identity_messages_include_complete_action_url_in_plain_text(
        string $mailClass,
        string $actionUrl,
        string $actionLabel,
    ): void {
        $mail = new $mailClass('Ayşe Yılmaz', $actionUrl);

        $mail->assertSeeInText('Merhaba Ayşe Yılmaz,');
        $mail->assertSeeInText($actionLabel);
        $mail->assertSeeInText($actionUrl);
        $mail->assertSeeInText('bu bağlantıyı kimseyle paylaşmayın');
    }

    /**
     * @param  class-string<Mailable>  $mailClass
     */
    #[DataProvider('identityMessagesForEscaping')]
    public function test_identity_messages_escape_organization_user_name_in_html(
        string $mailClass,
        string $actionUrl,
    ): void {
        $dangerousName = 'Ayşe <script>alert("xss")</script>';
        $mail = new $mailClass($dangerousName, $actionUrl);

        $html = $mail->render();

        $this->assertStringContainsString('Ayşe &lt;script&gt;', $html);
        $this->assertStringNotContainsString($dangerousName, $html);
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
    }

    /** @return array<string, array{class-string<Mailable>, string, string}> */
    public static function identityMessages(): array
    {
        return [
            'email verification' => [
                OrganizationUserEmailVerificationMail::class,
                'https://cleture-netzero-my.test/email/verify/01990dc0-c980-7000-8000-000000000001/plain-token',
                'E-posta adresimi doğrula:',
            ],
            'password reset' => [
                OrganizationUserPasswordResetMail::class,
                'https://cleture-netzero-my.test/reset-password/plain-token?email=ayse%40example.com',
                'Parolamı yenile:',
            ],
        ];
    }

    /** @return array<string, array{class-string<Mailable>, string}> */
    public static function identityMessagesForEscaping(): array
    {
        return [
            'email verification' => [
                OrganizationUserEmailVerificationMail::class,
                'https://cleture-netzero-my.test/email/verify/01990dc0-c980-7000-8000-000000000001/plain-token',
            ],
            'password reset' => [
                OrganizationUserPasswordResetMail::class,
                'https://cleture-netzero-my.test/reset-password/plain-token?email=ayse%40example.com',
            ],
        ];
    }

    /** @return array<string, array{class-string<Mailable>, list<string>, string, string, string}> */
    public static function localizedIdentityMessages(): array
    {
        return [
            'email verification' => [
                OrganizationUserEmailVerificationMail::class,
                [
                    'Ayşe Yılmaz',
                    'https://cleture-netzero-my.test/email/verify/01990dc0-c980-7000-8000-000000000001/plain-token',
                ],
                'Verify your email address | Cleture Net Zero',
                'Verify your email address',
                'Verify my email address:',
            ],
            'password reset' => [
                OrganizationUserPasswordResetMail::class,
                [
                    'Ayşe Yılmaz',
                    'https://cleture-netzero-my.test/reset-password/plain-token?email=ayse%40example.com',
                ],
                'Reset your password | Cleture Net Zero',
                'Reset your password',
                'Reset my password:',
            ],
            'password changed' => [
                OrganizationUserPasswordChangedMail::class,
                ['Ayşe Yılmaz'],
                'Your password was changed | Cleture Net Zero',
                'Your password was changed',
                'The password for your Cleture Net Zero account was changed successfully.',
            ],
        ];
    }
}
