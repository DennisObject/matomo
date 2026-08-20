<?php

declare(strict_types=1);

namespace App\Matomo\Geolocation;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseServerVariableMapping implements ServerVariableMapping
{
    /** @param array<string, string> $defaults */
    public function __construct(
        private ConnectionInterface $connection,
        private array $defaults,
    ) {}

    public function variables(): array
    {
        $stored = $this->connection
            ->table('plugin_setting')
            ->where('plugin_name', 'GeoIp2')
            ->where('user_login', '')
            ->whereIn('setting_name', array_map(
                static fn (string $name): string => 'geoip2var_'.$name,
                array_keys($this->defaults),
            ))
            ->pluck('setting_value', 'setting_name');
        $variables = $this->defaults;

        foreach ($stored as $name => $value) {
            if (! is_string($name) || ! is_string($value) || $value === '') {
                continue;
            }

            $resultName = str_replace('geoip2var_', '', $name);

            if (array_key_exists($resultName, $variables)) {
                $variables[$resultName] = $value;
            }
        }

        return $variables;
    }
}
