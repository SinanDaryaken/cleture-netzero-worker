<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Contracts\TenantDatabaseProvisioner;
use App\Tenancy\Contracts\TenantSchemaMigrator;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use LogicException;

class PostgreSqlTenantDatabaseProvisioner implements TenantDatabaseProvisioner, TenantSchemaMigrator
{
    private const PROVISIONING_CONNECTION = 'tenant_provisioning';

    private const SCHEMA_LOCK_PREFIX = 'netzero:tenant-schema:';

    public function __construct(
        private DatabaseManager $database,
        private Migrator $migrator,
        private TenantSchemaVersion $schemaVersion,
    ) {}

    public function provision(Tenant $tenant): string
    {
        $databaseName = $tenant->databaseName();
        $runtimeRole = $this->runtimeRole();

        $this->assertValidDatabaseName($databaseName);
        $provisionerConnection = $this->provisionerConnection($runtimeRole);
        $this->ensureDatabaseExists($provisionerConnection, $databaseName);

        return $this->migrateDatabase($tenant, $databaseName, $runtimeRole);
    }

    public function targetVersion(): string
    {
        return $this->schemaVersion->expected(array_keys(
            $this->migrator->getMigrationFiles([$this->migrationPath()]),
        ));
    }

    public function migrate(Tenant $tenant): string
    {
        $databaseName = $tenant->databaseName();
        $runtimeRole = $this->runtimeRole();

        $this->assertValidDatabaseName($databaseName);
        $provisionerConnection = $this->provisionerConnection($runtimeRole);

        if (! $this->databaseExists($provisionerConnection, $databaseName)) {
            throw new LogicException('The tenant database does not exist and cannot be upgraded.');
        }

        return $this->migrateDatabase($tenant, $databaseName, $runtimeRole);
    }

    private function migrateDatabase(
        Tenant $tenant,
        string $databaseName,
        string $runtimeRole,
    ): string {
        $provisioningConnection = $this->configureProvisioningConnection($databaseName);
        $migrationPath = $this->migrationPath();
        $lockAcquired = false;

        try {
            $lockAcquired = $this->acquireSchemaLock($provisioningConnection, $databaseName);

            if (! $lockAcquired) {
                throw new LogicException('Another process is already migrating the tenant database.');
            }

            $this->migrator->usingConnection(self::PROVISIONING_CONNECTION, function () use ($migrationPath): void {
                if (! $this->migrator->repositoryExists()) {
                    $this->migrator->getRepository()->createRepository();
                }

                $this->migrator->run(
                    $migrationPath,
                    ['pretend' => false, 'step' => false],
                );
            });

            $this->grantRuntimeAccess($provisioningConnection, $databaseName, $runtimeRole);
            $expectedSchemaVersion = $this->schemaVersion->verifiedCurrent(
                $provisioningConnection,
                array_keys($this->migrator->getMigrationFiles([$migrationPath])),
            );
        } finally {
            try {
                if ($lockAcquired) {
                    $this->releaseSchemaLock($provisioningConnection, $databaseName);
                }
            } finally {
                $this->database->purge(self::PROVISIONING_CONNECTION);
                config()->set('database.connections.'.self::PROVISIONING_CONNECTION, null);
            }
        }

        $runtimeSchemaVersion = $tenant->run(
            fn (): string => $this->schemaVersion->current($this->database->connection('tenant')),
        );

        if (! hash_equals($expectedSchemaVersion, $runtimeSchemaVersion)) {
            throw new LogicException('Tenant schema verification failed through the runtime connection.');
        }

        return $runtimeSchemaVersion;
    }

    private function provisionerConnection(string $runtimeRole): Connection
    {
        $provisionerConnection = (string) config('tenancy.provisioner_connection');

        if ($provisionerConnection === (string) config('processing_tasks.connection')) {
            throw new LogicException('Tenant provisioner and central task connections must be distinct.');
        }

        $connection = $this->database->connection($provisionerConnection);

        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('Tenant provisioning requires a PostgreSQL provisioner connection.');
        }

        $provisionerRole = $connection->getConfig('username');

        if (! is_string($provisionerRole)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,62}$/', $provisionerRole) !== 1
            || hash_equals($provisionerRole, $runtimeRole)) {
            throw new LogicException('Tenant provisioner and runtime database roles must be valid and distinct.');
        }

        return $connection;
    }

    private function ensureDatabaseExists(Connection $connection, string $databaseName): void
    {
        if ($this->databaseExists($connection, $databaseName)) {
            return;
        }

        $grammar = $connection->getQueryGrammar();

        $connection->statement(sprintf(
            'CREATE DATABASE %s WITH TEMPLATE=template0',
            $grammar->wrap($databaseName),
        ));
    }

    private function migrationPath(): string
    {
        return database_path('migrations/tenant');
    }

    private function configureProvisioningConnection(string $databaseName): Connection
    {
        $this->database->purge(self::PROVISIONING_CONNECTION);

        $provisionerConnection = $this->database->connection(
            (string) config('tenancy.provisioner_connection'),
        );
        $configuration = $provisionerConnection->getConfig();
        $configuration['database'] = $databaseName;
        unset($configuration['name']);

        config()->set('database.connections.'.self::PROVISIONING_CONNECTION, $configuration);

        return $this->database->connection(self::PROVISIONING_CONNECTION);
    }

    private function grantRuntimeAccess(
        Connection $connection,
        string $databaseName,
        string $runtimeRole,
    ): void {
        $grammar = $connection->getQueryGrammar();
        $wrappedDatabase = $grammar->wrap($databaseName);
        $wrappedRuntimeRole = $grammar->wrap($runtimeRole);
        $wrappedMigrationTable = $grammar->wrapTable(
            (string) config('database.migrations.table', 'migrations'),
        );

        $statements = [
            "GRANT CONNECT ON DATABASE {$wrappedDatabase} TO {$wrappedRuntimeRole}",
            "GRANT USAGE ON SCHEMA public TO {$wrappedRuntimeRole}",
            "GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$wrappedRuntimeRole}",
            "GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO {$wrappedRuntimeRole}",
            "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$wrappedRuntimeRole}",
            "ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO {$wrappedRuntimeRole}",
            "REVOKE INSERT, UPDATE, DELETE ON TABLE {$wrappedMigrationTable} FROM {$wrappedRuntimeRole}",
            'REVOKE CREATE ON SCHEMA public FROM PUBLIC',
            "REVOKE CREATE ON SCHEMA public FROM {$wrappedRuntimeRole}",
        ];

        foreach ($statements as $statement) {
            $connection->statement($statement);
        }
    }

    private function runtimeRole(): string
    {
        $runtimeConnection = (string) config('tenancy.database.template_tenant_connection');
        $runtimeRole = $this->database->connection($runtimeConnection)->getConfig('username');

        if (! is_string($runtimeRole)
            || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,62}$/', $runtimeRole) !== 1) {
            throw new LogicException('A valid tenant runtime database role is required.');
        }

        return $runtimeRole;
    }

    private function assertValidDatabaseName(string $databaseName): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $databaseName) !== 1) {
            throw new LogicException('The derived tenant database name is invalid.');
        }
    }

    private function databaseExists(Connection $connection, string $databaseName): bool
    {
        return $connection->table('pg_database')
            ->where('datname', $databaseName)
            ->exists();
    }

    private function acquireSchemaLock(Connection $connection, string $databaseName): bool
    {
        $result = $connection->selectOne(
            'SELECT pg_try_advisory_lock(hashtextextended(?, 0))::int AS acquired',
            [self::SCHEMA_LOCK_PREFIX.$databaseName],
        );

        return (int) ($result->acquired ?? 0) === 1;
    }

    private function releaseSchemaLock(Connection $connection, string $databaseName): void
    {
        $connection->selectOne(
            'SELECT pg_advisory_unlock(hashtextextended(?, 0))',
            [self::SCHEMA_LOCK_PREFIX.$databaseName],
        );
    }
}
