<?php

declare(strict_types=1);

namespace App\Providers;

use App\Matomo\Api\Methods\ApiMethodDispatcher;
use App\Matomo\Api\Methods\CoreApiMethodHandler;
use App\Matomo\Api\Methods\SitesManagerApiMethodHandler;
use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Authentication\DatabaseApiAccessAuthorizer;
use App\Matomo\Authentication\DatabaseSessionAuthenticator;
use App\Matomo\Config\InstallationConfig;
use App\Matomo\Database\MatomoDatabase;
use App\Matomo\Options\DatabaseOptionRepository;
use App\Matomo\Options\OptionRepository;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\Security\ConfiguredReportingApiIpAllowlist;
use App\Matomo\Security\ReportingApiIpAllowlist;
use App\Matomo\Sites\DatabaseSiteRepository;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
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
            MatomoDatabase::class,
            function (Application $application): MatomoDatabase {
                $installation = $application->make(InstallationConfig::class);
                $configuration = $application->make(Repository::class);
                $databases = $application->make(DatabaseManager::class);

                $configuration->set('database.connections.matomo', $installation->databaseConnection());
                $databases->purge('matomo');

                return new MatomoDatabase($databases->connection('matomo'));
            },
        );

        $this->app->singleton(
            ApiAccessAuthorizer::class,
            function (Application $application): ApiAccessAuthorizer {
                $installation = $application->make(InstallationConfig::class);
                $connection = $application->make(MatomoDatabase::class)->connection();

                return new DatabaseApiAccessAuthorizer(
                    connection: $connection,
                    salt: $installation->salt(),
                    onlyAllowSecureTokens: $installation->onlyAllowSecureTokens(),
                    sessions: new DatabaseSessionAuthenticator(
                        connection: $connection,
                        salt: $installation->salt(),
                        sessionLifetime: $installation->sessionLifetime(),
                        idleTimeout: $installation->sessionIdleTimeout(),
                    ),
                    events: $application->make(Dispatcher::class),
                );
            },
        );

        $this->app->singleton(
            SiteRepository::class,
            fn (Application $application): SiteRepository => new DatabaseSiteRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );

        $this->app->singleton(
            OptionRepository::class,
            fn (Application $application): OptionRepository => new DatabaseOptionRepository(
                $application->make(MatomoDatabase::class)->connection(),
            ),
        );

        $this->app->singleton(
            ClientIpResolver::class,
            function (Application $application): ClientIpResolver {
                $installation = $application->make(InstallationConfig::class);

                return new ClientIpResolver(
                    proxyHeaders: $installation->proxyClientHeaders(),
                    proxyIps: $installation->proxyIps(),
                    readLastProxyIp: $installation->proxyIpReadLastInList(),
                );
            },
        );

        $this->app->singleton(
            ReportingApiIpAllowlist::class,
            function (Application $application): ReportingApiIpAllowlist {
                $installation = $application->make(InstallationConfig::class);

                return new ConfiguredReportingApiIpAllowlist(
                    clientIps: $application->make(ClientIpResolver::class),
                    cache: $application->make(CacheRepository::class),
                    allowlistedIps: $installation->loginAllowlistIps(),
                    appliesToReportingApi: $installation->loginAllowlistAppliesToReportingApi(),
                );
            },
        );

        $this->app->singleton(
            ApiMethodDispatcher::class,
            fn (Application $application): ApiMethodDispatcher => new ApiMethodDispatcher([
                $application->make(CoreApiMethodHandler::class),
                $application->make(SitesManagerApiMethodHandler::class),
            ]),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void {}
}
