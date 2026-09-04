<?php

namespace App\Tenancy\Contracts;

use App\Models\Tenant;

interface TenantDatabaseProvisioner
{
    public function provision(Tenant $tenant): string;
}
