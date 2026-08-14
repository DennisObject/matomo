<?php

declare(strict_types=1);

namespace App\Matomo\Config;

use RuntimeException;

final readonly class InstallationConfig
{
    /**
     * @param  array<string, mixed>  $databaseConnection
     */
    private function __construct(
        private array $databaseConnection,
        #[\SensitiveParameter]
        private string $salt,
        private bool $onlyAllowSecureTokens,
        private int $sessionLifetime,
        private int $sessionIdleTimeout,
        /** @var list<string> */
        private array $loginAllowlistIps,
        private bool $loginAllowlistAppliesToReportingApi,
        /** @var list<string> */
        private array $proxyClientHeaders,
        /** @var list<string> */
        private array $proxyIps,
        private bool $proxyIpReadLastInList,
        private int $websitesCountToDisplay,
        /** @var list<string> */
        private array $activatedPlugins,
    ) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('The Matomo configuration file is not readable.');
        }

        $configuration = parse_ini_file($path, true, INI_SCANNER_RAW);

        if (! is_array($configuration)) {
            throw new RuntimeException('The Matomo configuration file is invalid.');
        }

        $database = $configuration['database'] ?? null;
        $general = $configuration['General'] ?? [];
        $plugins = $configuration['Plugins'] ?? [];

        if (! is_array($database) || ! is_array($general) || ! is_array($plugins)) {
            throw new RuntimeException('The Matomo configuration is missing required sections.');
        }

        $adapter = strtolower(self::string($database, 'adapter', 'pdo\\mysql'));

        if (! str_contains($adapter, 'mysql')) {
            throw new RuntimeException('The Matomo database adapter is not supported yet.');
        }

        $prefix = self::string($database, 'tables_prefix');

        if (preg_match('/^[a-zA-Z0-9_]*$/D', $prefix) !== 1) {
            throw new RuntimeException('The Matomo database table prefix is invalid.');
        }

        $salt = self::string($general, 'salt');

        if ($salt === '') {
            throw new RuntimeException('The Matomo authentication salt is missing.');
        }

        return new self(
            databaseConnection: self::buildDatabaseConnection($database, $prefix),
            salt: $salt,
            onlyAllowSecureTokens: self::boolean($general, 'only_allow_secure_auth_tokens'),
            sessionLifetime: self::positiveInteger($general, 'login_cookie_expire', 1_209_600),
            sessionIdleTimeout: self::positiveInteger(
                $general,
                'login_session_not_remembered_idle_timeout',
                3_600,
            ),
            loginAllowlistIps: self::parseLoginAllowlistIps($general),
            loginAllowlistAppliesToReportingApi: self::boolean(
                $general,
                'login_allowlist_apply_to_reporting_api_requests',
                true,
            ) || self::boolean($general, 'login_whitelist_apply_to_reporting_api_requests'),
            proxyClientHeaders: self::stringList($general, 'proxy_client_headers'),
            proxyIps: self::stringList($general, 'proxy_ips'),
            proxyIpReadLastInList: self::boolean($general, 'proxy_ip_read_last_in_list', true),
            websitesCountToDisplay: max(
                self::positiveInteger($general, 'site_selector_max_sites', 15),
                self::positiveInteger($general, 'autocomplete_min_sites', 5),
            ),
            activatedPlugins: self::stringList($plugins, 'Plugins'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function databaseConnection(): array
    {
        return $this->databaseConnection;
    }

    public function salt(): string
    {
        return $this->salt;
    }

    public function onlyAllowSecureTokens(): bool
    {
        return $this->onlyAllowSecureTokens;
    }

    public function sessionLifetime(): int
    {
        return $this->sessionLifetime;
    }

    public function sessionIdleTimeout(): int
    {
        return $this->sessionIdleTimeout;
    }

    /**
     * @return list<string>
     */
    public function loginAllowlistIps(): array
    {
        return $this->loginAllowlistIps;
    }

    public function loginAllowlistAppliesToReportingApi(): bool
    {
        return $this->loginAllowlistAppliesToReportingApi;
    }

    /**
     * @return list<string>
     */
    public function proxyClientHeaders(): array
    {
        return $this->proxyClientHeaders;
    }

    /**
     * @return list<string>
     */
    public function proxyIps(): array
    {
        return $this->proxyIps;
    }

    public function proxyIpReadLastInList(): bool
    {
        return $this->proxyIpReadLastInList;
    }

    public function websitesCountToDisplay(): int
    {
        return $this->websitesCountToDisplay;
    }

    /**
     * @return list<string>
     */
    public function activatedPlugins(): array
    {
        return $this->activatedPlugins;
    }

    /**
     * @param  array<string, mixed>  $database
     * @return array<string, mixed>
     */
    private static function buildDatabaseConnection(array $database, string $prefix): array
    {
        $connection = [
            'driver' => 'mysql',
            'host' => self::string($database, 'host', '127.0.0.1'),
            'port' => self::string($database, 'port', '3306'),
            'database' => self::requiredString($database, 'dbname'),
            'username' => self::string($database, 'username'),
            'password' => self::string($database, 'password'),
            'unix_socket' => self::string($database, 'unix_socket'),
            'charset' => self::string($database, 'charset', 'utf8'),
            'prefix' => $prefix,
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => self::sslOptions($database),
        ];
        $collation = self::string($database, 'collation');

        if ($collation !== '') {
            $connection['collation'] = $collation;
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $database
     * @return array<int, bool|string>
     */
    private static function sslOptions(array $database): array
    {
        if (! extension_loaded('pdo_mysql') || ! self::boolean($database, 'enable_ssl')) {
            return [];
        }

        $options = [];
        $mapping = [
            'ssl_ca' => \PDO::MYSQL_ATTR_SSL_CA,
            'ssl_cert' => \PDO::MYSQL_ATTR_SSL_CERT,
            'ssl_key' => \PDO::MYSQL_ATTR_SSL_KEY,
            'ssl_ca_path' => \PDO::MYSQL_ATTR_SSL_CAPATH,
            'ssl_cipher' => \PDO::MYSQL_ATTR_SSL_CIPHER,
        ];

        foreach ($mapping as $key => $attribute) {
            $value = self::string($database, $key);

            if ($value !== '') {
                $options[$attribute] = $value;
            }
        }

        if (self::boolean($database, 'ssl_no_verify')) {
            $options[\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function requiredString(array $values, string $key): string
    {
        $value = self::string($values, $key);

        if ($value === '') {
            throw new RuntimeException("The Matomo configuration value [{$key}] is missing.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function string(array $values, string $key, string $default = ''): string
    {
        $value = $values[$key] ?? $default;

        if (! is_scalar($value)) {
            throw new RuntimeException("The Matomo configuration value [{$key}] is invalid.");
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function boolean(array $values, string $key, bool $default = false): bool
    {
        return in_array(
            strtolower(self::string($values, $key, $default ? '1' : '0')),
            ['1', 'true', 'yes', 'on'],
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private static function stringList(array $values, string $key): array
    {
        $value = $values[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            is_string(...),
        ));
    }

    /**
     * @param  array<string, mixed>  $general
     * @return list<string>
     */
    private static function parseLoginAllowlistIps(array $general): array
    {
        $allowlist = self::stringList($general, 'login_allowlist_ip');

        return $allowlist !== []
            ? $allowlist
            : self::stringList($general, 'login_whitelist_ip');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function positiveInteger(array $values, string $key, int $default): int
    {
        $value = self::string($values, $key, (string) $default);

        return preg_match('/^[1-9]\d*$/D', $value) === 1 ? (int) $value : $default;
    }
}
