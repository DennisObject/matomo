<?php

declare(strict_types=1);

namespace App\Matomo\AiProviders;

use Illuminate\Database\ConnectionInterface;
use JsonException;

final readonly class DatabaseAiProviderSettingsRepository implements AiProviderSettingsRepository
{
    private const string PLUGIN = 'AIProviders';

    public function __construct(private ConnectionInterface $connection) {}

    public function read(): AiProviderStoredSettings
    {
        $rows = $this->connection
            ->table('plugin_setting')
            ->select(['setting_name', 'setting_value', 'json_encoded'])
            ->where('plugin_name', self::PLUGIN)
            ->where('user_login', '')
            ->whereIn('setting_name', ['defaultProvider', 'defaultCapabilityLevel', 'providerCredentials'])
            ->get()
            ->keyBy('setting_name');

        return new AiProviderStoredSettings(
            defaultProvider: $this->stringValue($rows->get('defaultProvider')),
            defaultCapabilityLevel: $this->stringValue($rows->get('defaultCapabilityLevel'), 'instant'),
            providerCredentials: $this->credentials($rows->get('providerCredentials')),
        );
    }

    public function save(#[\SensitiveParameter] AiProviderStoredSettings $settings): void
    {
        $this->connection->transaction(function () use ($settings): void {
            $this->write('defaultProvider', $settings->defaultProvider, false);
            $this->write('defaultCapabilityLevel', $settings->defaultCapabilityLevel, false);
            $this->write(
                'providerCredentials',
                json_encode($settings->providerCredentials, JSON_THROW_ON_ERROR),
                true,
            );
        });
    }

    private function write(string $name, string $value, bool $jsonEncoded): void
    {
        $this->connection->table('plugin_setting')->updateOrInsert(
            ['plugin_name' => self::PLUGIN, 'user_login' => '', 'setting_name' => $name],
            ['setting_value' => $value, 'json_encoded' => $jsonEncoded ? 1 : 0],
        );
    }

    private function stringValue(?object $row, string $default = ''): string
    {
        $value = $row === null ? null : (get_object_vars($row)['setting_value'] ?? null);

        return is_string($value) ? $value : $default;
    }

    /**
     * @return array<string, array{apiKey: string, endpointUrl: string, model: string, useFipsEndpoint: bool}>
     */
    private function credentials(?object $row): array
    {
        if ($row === null || (int) ($row->json_encoded ?? 0) !== 1) {
            return [];
        }

        $value = get_object_vars($row)['setting_value'] ?? null;

        if (! is_string($value)) {
            return [];
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $credentials = [];

        foreach ($decoded as $providerId => $configuration) {
            if (! is_string($providerId) || ! is_array($configuration)) {
                continue;
            }

            $credentials[$providerId] = [
                'apiKey' => is_string($configuration['apiKey'] ?? null) ? $configuration['apiKey'] : '',
                'endpointUrl' => is_string($configuration['endpointUrl'] ?? null)
                    ? $configuration['endpointUrl']
                    : '',
                'model' => is_string($configuration['model'] ?? null) ? $configuration['model'] : '',
                'useFipsEndpoint' => ! empty($configuration['useFipsEndpoint']),
            ];
        }

        return $credentials;
    }
}
