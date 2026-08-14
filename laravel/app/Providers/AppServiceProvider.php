<?php

declare(strict_types=1);

namespace App\Providers;

use App\Matomo\Authentication\DatabaseVersionAccessAuthorizer;
use App\Matomo\Authentication\VersionAccessAuthorizer;
use App\Matomo\Config\InstallationConfig;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(InstallationConfig::class, function (Application $application): InstallationConfig {
            $path = $application->make(Repository::class)->get('matomo.config_path');

            if (! is_string($path)) {
                throw new RuntimeException('The Matomo configuration path is invalid.');
            }

            return InstallationConfig::fromFile($path);
        });

        $this->app->singleton(
            VersionAccessAuthorizer::class,
            function (Application $application): VersionAccessAuthorizer {
                $installation = $application->make(InstallationConfig::class);
                $configuration = $application->make(Repository::class);
                $databases = $application->make(DatabaseManager::class);

                $configuration->set('database.connections.matomo', $installation->databaseConnection());
                $databases->purge('matomo');

                return new DatabaseVersionAccessAuthorizer(
                    connection: $databases->connection('matomo'),
                    salt: $installation->salt(),
                    onlyAllowSecureTokens: $installation->onlyAllowSecureTokens(),
                );
            },
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {}
}
