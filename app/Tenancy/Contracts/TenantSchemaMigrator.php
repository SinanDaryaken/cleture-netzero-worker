<?php

namespace App\Tenancy\Contracts;

use App\Models\Tenant;

interface TenantSchemaMigrator
{
    public function targetVersion(): string;

    public function migrate(Tenant $tenant): string;
}
