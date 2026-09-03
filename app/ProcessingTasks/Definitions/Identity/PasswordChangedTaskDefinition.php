<?php

namespace App\ProcessingTasks\Definitions\Identity;

use App\Jobs\Identity\SendOrganizationUserPasswordChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

class PasswordChangedTaskDefinition extends OrganizationUserMailTaskDefinition
{
    protected function makeJob(string $organizationUserId, ?string $locale): ShouldQueue
    {
        return new SendOrganizationUserPasswordChanged($organizationUserId, $locale);
    }
}
