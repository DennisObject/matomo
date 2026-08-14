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

        if (! is_array($database) || ! is_array($general)) {
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
    private static function boolean(array $values, string $key): bool
    {
        return in_array(strtolower(self::string($values, $key, '0')), ['1', 'true', 'yes', 'on'], true);
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
