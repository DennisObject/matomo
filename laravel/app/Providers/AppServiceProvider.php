<?php

declare(strict_types=1);

namespace App\Providers;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\DatabaseApiAccessAuthorizer;
use App\Matomo\Authentication\DatabaseSessionAuthenticator;
use App\Matomo\Config\InstallationConfig;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Security\ConfiguredReportingApiIpAllowlist;
use App\Matomo\Security\ReportingApiIpAllowlist;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
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
            ApiAccessAuthorizer::class,
            function (Application $application): ApiAccessAuthorizer {
                $installation = $application->make(InstallationConfig::class);
                $configuration = $application->make(Repository::class);
                $databases = $application->make(DatabaseManager::class);

                $configuration->set('database.connections.matomo', $installation->databaseConnection());
                $databases->purge('matomo');

                return new DatabaseApiAccessAuthorizer(
                    connection: $databases->connection('matomo'),
                    salt: $installation->salt(),
                    onlyAllowSecureTokens: $installation->onlyAllowSecureTokens(),
                    sessions: new DatabaseSessionAuthenticator(
                        connection: $databases->connection('matomo'),
                        salt: $installation->salt(),
                        sessionLifetime: $installation->sessionLifetime(),
                        idleTimeout: $installation->sessionIdleTimeout(),
                    ),
                );
            },
        );

        $this->app->singleton(
            ReportingApiIpAllowlist::class,
            function (Application $application): ReportingApiIpAllowlist {
                $installation = $application->make(InstallationConfig::class);

                return new ConfiguredReportingApiIpAllowlist(
                    clientIps: new ClientIpResolver(
                        proxyHeaders: $installation->proxyClientHeaders(),
                        proxyIps: $installation->proxyIps(),
                        readLastProxyIp: $installation->proxyIpReadLastInList(),
                    ),
                    cache: $application->make(CacheRepository::class),
                    allowlistedIps: $installation->loginAllowlistIps(),
                    appliesToReportingApi: $installation->loginAllowlistAppliesToReportingApi(),
                );
            },
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {}
}
