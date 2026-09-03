<?php

namespace App\Jobs\Identity;

use App\Identity\Exceptions\IdentityMailDeliveryException;
use App\Identity\IdentityLinkFactory;
use App\Mail\Identity\OrganizationUserPasswordResetMail;
use App\Models\OrganizationUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Throwable;

class SendOrganizationUserPasswordReset implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $organizationUserId,
        public readonly ?string $locale = null,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    /**
     * Execute the job.
     */
    public function handle(IdentityLinkFactory $links): void
    {
        try {
            $organizationUser = OrganizationUser::query()->find($this->organizationUserId);

            if ($organizationUser === null) {
                return;
            }

            $passwordBroker = Password::broker((string) config('identity.password_reset.broker'));

            if ($passwordBroker->getRepository()->recentlyCreatedToken($organizationUser)) {
                return;
            }

            $token = $passwordBroker->createToken($organizationUser);
            $actionUrl = $links->passwordReset($organizationUser, $token);

            Mail::to($organizationUser->email)->send(
                (new OrganizationUserPasswordResetMail($organizationUser->name, $actionUrl))
                    ->locale($this->locale ?? (string) config('identity.mail.fallback_locale')),
            );
        } catch (Throwable $exception) {
            Log::error('Organization user password reset delivery failed.', [
                'organization_user_id' => $this->organizationUserId,
                'cause' => $exception::class,
            ]);

            throw new IdentityMailDeliveryException('Password reset delivery failed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Organization user password reset job exhausted its retries.', [
            'organization_user_id' => $this->organizationUserId,
            'cause' => $exception === null ? null : $exception::class,
        ]);
    }
}
