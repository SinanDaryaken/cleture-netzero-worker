<?php

namespace App\Providers;

use App\ProcessingTasks\WorkerIdentity;
use App\Tenancy\Contracts\TenantDatabaseProvisioner;
use App\Tenancy\Contracts\TenantSchemaMigrator;
use App\Tenancy\PostgreSqlTenantDatabaseProvisioner;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WorkerIdentity::class);
        $this->app->singleton(PostgreSqlTenantDatabaseProvisioner::class);
        $this->app->bind(
            TenantDatabaseProvisioner::class,
            fn (Application $app): PostgreSqlTenantDatabaseProvisioner => $app->make(PostgreSqlTenantDatabaseProvisioner::class),
        );
        $this->app->bind(
            TenantSchemaMigrator::class,
            fn (Application $app): PostgreSqlTenantDatabaseProvisioner => $app->make(PostgreSqlTenantDatabaseProvisioner::class),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
