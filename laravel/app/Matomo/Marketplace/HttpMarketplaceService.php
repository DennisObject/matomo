<?php

declare(strict_types=1);

namespace App\Matomo\Marketplace;

use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Security\EgressHostResolver;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final readonly class HttpMarketplaceService implements MarketplaceService
{
    private const string LICENSE_OPTION = 'marketplace_license_key';

    public function __construct(
        private Factory $http,
        private ConnectionInterface $connection,
        private MutableOptionRepository $options,
        private EgressHostResolver $hosts,
        private string $endpoint,
        /** @var list<string> */
        private array $allowedEmailDomains = [],
    ) {}

    public function createAccount(string $email): void
    {
        $email = trim($email);

        if (($this->options->value(self::LICENSE_OPTION) ?? '') !== '') {
            throw new MarketplaceException('Marketplace_CreateAccountErrorLicenseExists');
        }

        $this->validateEmail($email);
        $response = $this->post('createAccount', ['email' => $email]);
        $licenseKey = trim((string) $response->json('data.license_key', ''));

        if ($response->status() !== 200 || $licenseKey === '') {
            throw new MarketplaceException(match ($response->status()) {
                400 => 'Marketplace_CreateAccountErrorAPIEmailInvalid',
                409 => 'Marketplace_CreateAccountErrorAPIEmailExists',
                default => 'Marketplace_CreateAccountErrorAPI',
            });
        }

        $this->options->set(self::LICENSE_OPTION, $licenseKey);
    }

    public function deleteLicenseKey(): void
    {
        $this->options->delete(self::LICENSE_OPTION);
    }

    public function requestTrial(string $pluginName, string $login): void
    {
        $this->validatePluginName($pluginName);
        $optionName = 'Marketplace.PluginTrialRequest.'.$pluginName;
        if (($this->options->value($optionName) ?? '') !== '') {
            return;
        }

        $response = $this->get('plugins/'.rawurlencode($pluginName).'/info');
        $name = (string) $response->json('name', '');

        if (! $response->successful() || $name === '') {
            throw new MarketplaceException('Unable to find plugin with given name: '.$pluginName, 404);
        }

        $displayName = (string) $response->json('displayName', $name);
        $this->options->set($optionName, json_encode([
            'requestTime' => time(),
            'displayName' => $displayName !== '' ? $displayName : $name,
            'dismissed' => [],
            'requestedBy' => $login,
        ], JSON_THROW_ON_ERROR));
    }

    public function startFreeTrial(string $pluginName): void
    {
        $this->validatePluginName($pluginName);
        $response = $this->post('plugins/'.rawurlencode($pluginName).'/freeTrial', [
            'num_users' => $this->connection->table('user')->where('login', '<>', 'anonymous')->count('login'),
            'num_websites' => $this->connection->table('site')->count('idsite'),
        ], $this->licenseKey());

        if ($response->status() !== 201 || trim($response->body()) !== '') {
            throw new MarketplaceException('Marketplace_TrialStartErrorAPI');
        }
    }

    public function saveLicenseKey(#[\SensitiveParameter] string $licenseKey): void
    {
        $licenseKey = trim($licenseKey);
        $response = $this->post('consumer/validate', [], $licenseKey);

        if (! $response->successful() || $response->json('isValid') !== true) {
            throw new MarketplaceException('Marketplace_ExceptionLinceseKeyIsNotValid');
        }

        $this->options->set(self::LICENSE_OPTION, $licenseKey);
    }

    private function validateEmail(string $email): void
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new MarketplaceException('Marketplace_CreateAccountErrorEmailInvalid');
        }

        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        $allowedDomains = array_map(
            static fn (string $allowedDomain): string => strtolower(trim($allowedDomain)),
            $this->allowedEmailDomains,
        );
        if ($allowedDomains !== [] && ! in_array($domain, $allowedDomains, true)) {
            throw new MarketplaceException('The email address domain is not allowed.');
        }
    }

    private function validatePluginName(string $pluginName): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9]{0,59}$/D', $pluginName) !== 1) {
            throw new MarketplaceException('Invalid plugin name given');
        }
    }

    private function licenseKey(): string
    {
        return trim($this->options->value(self::LICENSE_OPTION) ?? '');
    }

    /** @param array<string, int|string> $data */
    private function post(string $path, array $data, string $licenseKey = ''): Response
    {
        if ($licenseKey !== '') {
            $data['access_token'] = $licenseKey;
        }

        return $this->request($path)->asForm()->post($this->url($path), $data);
    }

    private function get(string $path): Response
    {
        return $this->request($path)->get($this->url($path));
    }

    private function request(string $path): PendingRequest
    {
        $parts = parse_url($this->url($path));
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new MarketplaceException('The Marketplace endpoint is invalid.', 500);
        }

        [$canonicalHost, $address] = $this->hosts->resolveTarget($host);
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $curl = [CURLOPT_PROXY => ''];
        if ($canonicalHost !== $address) {
            $address = str_contains($address, ':') ? "[{$address}]" : $address;
            $curl[CURLOPT_RESOLVE] = ["{$canonicalHost}:{$port}:{$address}"];
        }

        return $this->http->timeout(60)->connectTimeout(5)->withOptions([
            'allow_redirects' => false,
            'proxy' => '',
            'curl' => $curl,
        ]);
    }

    private function url(string $path): string
    {
        return rtrim($this->endpoint, '/').'/'.ltrim($path, '/');
    }
}
