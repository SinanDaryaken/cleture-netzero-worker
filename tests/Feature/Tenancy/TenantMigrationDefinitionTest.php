<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantMigrationDefinitionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'tenant_migration_test',
            'database.connections.tenant_migration_test' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => '5432',
                'database' => 'testing',
                'username' => 'testing',
                'password' => '',
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        DB::purge('tenant_migration_test');

        parent::tearDown();
    }

    public function test_organizational_unit_primary_key_is_declared_before_self_referencing_foreign_key(): void
    {
        $queries = $this->migrationQueries(
            '2026_09_04_084126_create_organizational_units_table.php',
        );

        $primaryKeyIndex = $queries->search(
            static fn (string $query): bool => str_contains($query, 'add primary key ("id")'),
        );
        $foreignKeyIndex = $queries->search(
            static fn (string $query): bool => str_contains($query, 'organizational_units_parent_id_foreign'),
        );

        $this->assertIsInt($primaryKeyIndex);
        $this->assertIsInt($foreignKeyIndex);
        $this->assertLessThan($foreignKeyIndex, $primaryKeyIndex);
    }

    public function test_organization_unit_types_migration_adds_nullable_restricted_type_reference(): void
    {
        $queries = $this->migrationQueries(
            '2026_09_04_111120_create_organization_unit_types_table_and_add_type_to_organizational_units.php',
        );
        $sql = $queries->implode("\n");

        $this->assertStringContainsString('create table "organization_unit_types"', $sql);
        $this->assertStringContainsString('"id" uuid not null default uuidv7()', $sql);
        $this->assertStringContainsString('add primary key ("id")', $sql);
        $this->assertStringContainsString('organization_unit_types_name_unique', $sql);
        $this->assertStringContainsString('organization_unit_types_id_uuid_v7_check', $sql);
        $this->assertStringContainsString('organization_unit_types_name_canonical_check', $sql);
        $this->assertStringContainsString('organization_unit_types_sort_order_check', $sql);
        $this->assertStringContainsString('add column "organization_unit_type_id" uuid null', $sql);
        $this->assertStringContainsString('organizational_units_organization_unit_type_id_foreign', $sql);
        $this->assertStringContainsString('on delete restrict', $sql);
        $this->assertStringContainsString('organizational_units_organization_unit_type_id_index', $sql);
    }

    /** @return Collection<int, string> */
    private function migrationQueries(string $filename): Collection
    {
        $migration = require database_path('migrations/tenant/'.$filename);

        return collect(DB::connection()->pretend(
            static fn (): mixed => $migration->up(),
        ))->pluck('query')->values();
    }
}
