<?php

namespace App\ProcessingTasks\Definitions\Identity;

use App\Jobs\Identity\SendOrganizationUserPasswordReset;
use Illuminate\Contracts\Queue\ShouldQueue;

class PasswordResetTaskDefinition extends OrganizationUserMailTaskDefinition
{
    protected function makeJob(string $organizationUserId, ?string $locale): ShouldQueue
    {
        return new SendOrganizationUserPasswordReset($organizationUserId, $locale);
    }
}
