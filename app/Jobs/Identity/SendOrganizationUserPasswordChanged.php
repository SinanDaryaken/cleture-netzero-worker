<?php

namespace App\Jobs\Identity;

use App\Identity\Exceptions\IdentityMailDeliveryException;
use App\Mail\Identity\OrganizationUserPasswordChangedMail;
use App\Models\OrganizationUser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendOrganizationUserPasswordChanged implements ShouldQueue
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
    public function handle(): void
    {
        try {
            $organizationUser = OrganizationUser::query()->find($this->organizationUserId);

            if ($organizationUser === null) {
                return;
            }

            Mail::to($organizationUser->email)->send(
                (new OrganizationUserPasswordChangedMail($organizationUser->name))
                    ->locale($this->locale ?? (string) config('identity.mail.fallback_locale')),
            );
        } catch (Throwable $exception) {
            Log::error('Organization user password changed delivery failed.', [
                'organization_user_id' => $this->organizationUserId,
                'cause' => $exception::class,
            ]);

            throw new IdentityMailDeliveryException('Password changed delivery failed.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Organization user password changed job exhausted its retries.', [
            'organization_user_id' => $this->organizationUserId,
            'cause' => $exception === null ? null : $exception::class,
        ]);
    }
}
