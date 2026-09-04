<?php

namespace App\Models;

use App\Enums\TenantProvisioningStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Concerns\HasDatabase;

class Tenant extends Model implements TenantWithDatabase
{
    use CentralConnection, HasDatabase, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'schema_version',
    ];

    public function getTenantKeyName(): string
    {
        return $this->getKeyName();
    }

    public function getTenantKey(): mixed
    {
        return $this->getKey();
    }

    public function getInternal(string $key): mixed
    {
        return match ($key) {
            'db_name' => $this->databaseName(),
            'db_connection' => (string) config('tenancy.database.template_tenant_connection'),
            default => null,
        };
    }

    public function setInternal(string $key, mixed $value): never
    {
        throw new LogicException("Tenant database setting [{$key}] cannot be persisted.");
    }

    public function run(callable $callback): mixed
    {
        $originalTenant = tenant();

        try {
            tenancy()->initialize($this);

            return $callback($this);
        } finally {
            if ($originalTenant === null) {
                tenancy()->end();
            } else {
                tenancy()->initialize($originalTenant);
            }
        }
    }

    public static function internalPrefix(): string
    {
        return 'tenancy_';
    }

    public function databaseName(): string
    {
        return (string) config('tenancy.database.prefix')
            .str_replace('-', '', (string) $this->getTenantKey())
            .(string) config('tenancy.database.suffix');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provisioning_status' => TenantProvisioningStatus::class,
            'active' => 'boolean',
        ];
    }
}
