<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseAnonymisationSettingsRepository implements AnonymisationSettingsRepository
{
    /** @var array<string, bool|int|string> */
    private const array DEFAULTS = [
        'useAnonymizedIpForVisitEnrichment' => false,
        'ipAddressMaskLength' => 2,
        'doNotTrackEnabled' => false,
        'ipAnonymizerEnabled' => true,
        'forceCookielessTracking' => false,
        'anonymizeUserId' => false,
        'anonymizeOrderId' => false,
        'anonymizeReferrer' => '',
        'randomizeConfigId' => false,
    ];

    public function __construct(private ConnectionInterface $connection) {}

    public function values(?int $idSite): array
    {
        $values = [];
        foreach (self::DEFAULTS as $name => $default) {
            $stored = $idSite === null ? null : $this->option($this->name($name, $idSite));
            $stored ??= $this->option($this->name($name, null));
            $values[$name] = $this->typed($stored, $default);
        }

        return $values;
    }

    public function usesSiteSettings(int $idSite): bool
    {
        return $this->connection->table('option')
            ->where('option_name', 'like', sprintf('PrivacyManager.idSite(%d).%%', $idSite))
            ->exists();
    }

    public function replace(?int $idSite, array $values): void
    {
        $this->connection->transaction(function () use ($idSite, $values): void {
            foreach ($values as $name => $value) {
                $this->connection->table('option')->updateOrInsert(
                    ['option_name' => $this->name($name, $idSite)],
                    [
                        'option_value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                        'autoload' => 0,
                    ],
                );
            }
        });
    }

    public function removeSiteSettings(int $idSite): void
    {
        $this->connection->table('option')
            ->where('option_name', 'like', sprintf('PrivacyManager.idSite(%d).%%', $idSite))
            ->delete();
    }

    private function option(string $name): ?string
    {
        $value = $this->connection->table('option')->where('option_name', $name)->value('option_value');

        return is_scalar($value) ? (string) $value : null;
    }

    private function name(string $name, ?int $idSite): string
    {
        return 'PrivacyManager.'.($idSite === null ? '' : sprintf('idSite(%d).', $idSite)).$name;
    }

    private function typed(?string $stored, bool|int|string $default): bool|int|string
    {
        if ($stored === null) {
            return $default;
        }

        return match (gettype($default)) {
            'boolean' => in_array(strtolower($stored), ['1', 'true'], true),
            'integer' => (int) $stored,
            default => $stored,
        };
    }
}
