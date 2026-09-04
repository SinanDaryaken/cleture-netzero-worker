<?php

use App\Models\Tenant;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\Domain;
use Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager;

return [
    'tenant_model' => Tenant::class,
    'id_generator' => null,
    'domain_model' => Domain::class,
    'central_domains' => [],
    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
    ],
    'database' => [
        'central_connection' => env('PROCESSING_TASK_CONNECTION', 'central'),
        'template_tenant_connection' => env('TENANT_RUNTIME_CONNECTION', 'tenant_template'),
        'prefix' => env('TENANT_DATABASE_PREFIX', 'netzero_'),
        'suffix' => '',
        'managers' => [
            'pgsql' => PostgreSQLDatabaseManager::class,
        ],
    ],
    'cache' => [
        'tag_base' => 'tenant',
    ],
    'filesystem' => [
        'suffix_base' => 'tenant',
        'disks' => [],
        'root_override' => [],
        'suffix_storage_path' => false,
        'asset_helper_tenancy' => false,
    ],
    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [],
    ],
    'features' => [],
    'routes' => false,
    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],
    'seeder_parameters' => [
        '--force' => true,
    ],
    'provisioner_connection' => env('TENANT_PROVISIONER_CONNECTION', 'tenant_provisioner'),
];
