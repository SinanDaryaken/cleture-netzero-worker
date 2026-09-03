<?php

namespace App\ProcessingTasks\Definitions\Identity;

use App\Jobs\Identity\SendOrganizationUserEmailVerification;
use Illuminate\Contracts\Queue\ShouldQueue;

class EmailVerificationTaskDefinition extends OrganizationUserMailTaskDefinition
{
    protected function makeJob(string $organizationUserId, ?string $locale): ShouldQueue
    {
        return new SendOrganizationUserEmailVerification($organizationUserId, $locale);
    }
}
