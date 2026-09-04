<?php

namespace App\Tenancy;

use Illuminate\Database\Connection;
use LogicException;

class TenantSchemaVersion
{
    public function current(Connection $connection): string
    {
        return $this->fingerprint($this->appliedMigrations($connection));
    }

    /** @param list<string> $migrations */
    public function expected(array $migrations): string
    {
        sort($migrations);

        return $this->fingerprint($migrations);
    }

    /** @param list<string> $expectedMigrations */
    public function verifiedCurrent(Connection $connection, array $expectedMigrations): string
    {
        $appliedMigrations = $this->appliedMigrations($connection);
        sort($expectedMigrations);

        if ($appliedMigrations !== $expectedMigrations) {
            throw new LogicException('Tenant migration state does not match the canonical migration set.');
        }

        return $this->expected($appliedMigrations);
    }

    /** @return list<string> */
    private function appliedMigrations(Connection $connection): array
    {
        return $connection
            ->table((string) config('database.migrations.table', 'migrations'))
            ->orderBy('migration')
            ->pluck('migration')
            ->map(static fn (mixed $migration): string => (string) $migration)
            ->all();
    }

    /** @param list<string> $migrations */
    private function fingerprint(array $migrations): string
    {
        return 'sha256:'.hash('sha256', implode("\n", $migrations));
    }
}
