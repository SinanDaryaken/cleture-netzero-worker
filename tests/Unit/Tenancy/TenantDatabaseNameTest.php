<?php

namespace Tests\Unit\Tenancy;

use App\Models\Tenant;
use LogicException;
use Tests\TestCase;

class TenantDatabaseNameTest extends TestCase
{
    public function test_database_name_is_derived_deterministically_from_uuid(): void
    {
        config([
            'tenancy.database.prefix' => 'netzero_',
            'tenancy.database.suffix' => '',
        ]);
        $tenant = new Tenant;
        $tenant->setAttribute('id', '0199a5e6-7d10-7f2d-8b31-2ee0833e7f85');

        $this->assertSame(
            'netzero_0199a5e67d107f2d8b312ee0833e7f85',
            $tenant->databaseName(),
        );
        $this->assertSame($tenant->databaseName(), $tenant->getInternal('db_name'));
    }

    public function test_database_credentials_cannot_be_persisted_on_tenant(): void
    {
        $tenant = new Tenant;

        $this->expectException(LogicException::class);

        $tenant->setInternal('db_password', 'secret');
    }
}
