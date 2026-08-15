<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

use App\Matomo\Options\MutableOptionRepository;
use Carbon\CarbonImmutable;
use JsonException;

final readonly class TrackerInstallationCheck
{
    public const string QUERY_PARAMETER = 'tracker_install_check';

    private const string OPTION_PREFIX = 'JsTrackerInstallCheck_';

    private const int MAX_NONCE_AGE_SECONDS = 30;

    public function __construct(private MutableOptionRepository $options) {}

    public function successful(int $siteId, string $nonce, string $mainUrl): bool
    {
        $nonces = $nonce === '' ? $this->nonces($siteId) : $this->currentNonces($siteId);

        if ($nonce !== '') {
            return (bool) ($nonces[$nonce]['isSuccessful'] ?? false);
        }

        if ($nonces === []) {
            return false;
        }

        if (count($nonces) === 1) {
            $check = array_values($nonces)[0];

            return $check['isSuccessful'];
        }

        foreach ($nonces as $check) {
            if ($mainUrl !== '' && $check['url'] !== '' && $check['url'] === $mainUrl) {
                return $check['isSuccessful'];
            }
        }

        return false;
    }

    /** @return array{url: string, nonce: string} */
    public function initiate(int $siteId, string $url): array
    {
        $nonces = $this->currentNonces($siteId);
        $now = CarbonImmutable::now('UTC')->getTimestamp();
        $existingNonce = $this->nonceForUrl($nonces, $url);

        if ($existingNonce !== null
            && $now - $nonces[$existingNonce]['time'] < self::MAX_NONCE_AGE_SECONDS) {
            $nonces[$existingNonce] = [...$nonces[$existingNonce], 'time' => $now];
            $nonce = $existingNonce;
        } else {
            $nonce = bin2hex(random_bytes(16));
            $nonces[$nonce] = [
                'time' => $now,
                'url' => $url,
                'isSuccessful' => false,
            ];
        }

        $this->store($siteId, $nonces);
        $separator = parse_url($url, PHP_URL_QUERY) ? '&' : '?';

        return [
            'url' => $url.$separator.self::QUERY_PARAMETER.'='.$nonce,
            'nonce' => $nonce,
        ];
    }

    public function markSuccessful(int $siteId, string $nonce): bool
    {
        $nonces = $this->currentNonces($siteId);

        if (! isset($nonces[$nonce])) {
            return false;
        }

        $nonces[$nonce]['isSuccessful'] = true;
        $this->store($siteId, $nonces);

        return true;
    }

    /** @return array<string, array{time: int, url: string, isSuccessful: bool}> */
    private function currentNonces(int $siteId): array
    {
        $now = CarbonImmutable::now('UTC')->getTimestamp();

        return array_filter(
            $this->nonces($siteId),
            static fn (array $check): bool => $check['time'] === 0
                || $now - $check['time'] <= self::MAX_NONCE_AGE_SECONDS,
        );
    }

    /** @return array<string, array{time: int, url: string, isSuccessful: bool}> */
    private function nonces(int $siteId): array
    {
        $value = $this->options->value(self::OPTION_PREFIX.$siteId);

        if ($value === null || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded) || array_key_exists('nonce', $decoded)) {
            return [];
        }

        $nonces = [];

        foreach ($decoded as $nonce => $check) {
            if (! is_string($nonce)
                || preg_match('/^[a-f0-9]{32}$/iD', $nonce) !== 1
                || ! is_array($check)) {
                continue;
            }

            $time = $check['time'] ?? null;
            $url = $check['url'] ?? null;
            $successful = $check['isSuccessful'] ?? null;

            if (! is_numeric($time) || ! is_string($url)) {
                continue;
            }

            $nonces[$nonce] = [
                'time' => (int) $time,
                'url' => $url,
                'isSuccessful' => (bool) $successful,
            ];
        }

        return $nonces;
    }

    /**
     * @param  array<string, array{time: int, url: string, isSuccessful: bool}>  $nonces
     */
    private function store(int $siteId, array $nonces): void
    {
        $this->options->set(
            self::OPTION_PREFIX.$siteId,
            json_encode($nonces, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param  array<string, array{time: int, url: string, isSuccessful: bool}>  $nonces
     */
    private function nonceForUrl(array $nonces, string $url): ?string
    {
        foreach ($nonces as $nonce => $check) {
            if ($url !== '' && $check['url'] !== '' && $check['url'] === $url) {
                return $nonce;
            }
        }

        return null;
    }
}
