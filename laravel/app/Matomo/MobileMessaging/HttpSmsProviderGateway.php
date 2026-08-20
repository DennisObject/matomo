<?php

declare(strict_types=1);

namespace App\Matomo\MobileMessaging;

use App\Matomo\Security\EgressHostResolver;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;

final readonly class HttpSmsProviderGateway implements SmsProviderGateway
{
    private const string HOST = 'json.aspsms.com';

    public function __construct(private Factory $http, private EgressHostResolver $hosts) {}

    public function verify(string $provider, #[\SensitiveParameter] array $credentials): void
    {
        $this->credit($provider, $credentials);
    }

    public function credit(string $provider, #[\SensitiveParameter] array $credentials): int|string
    {
        if ($provider === 'Development') {
            return 'Balance: 42';
        }

        $result = $this->call($provider, 'CheckCredits', $credentials);
        $credits = $result->json('Credits');

        return is_int($credits) || is_string($credits) ? $credits : 0;
    }

    public function send(
        string $provider,
        #[\SensitiveParameter]
        array $credentials,
        string $text,
        string $phoneNumber,
        string $from,
    ): void {
        if ($provider === 'Development') {
            return;
        }

        $this->call($provider, 'SendTextSMS', $credentials, [
            'Recipients' => [$phoneNumber],
            'MessageText' => mb_substr($text, 0, 603),
            'Originator' => mb_substr($from, 0, 11),
            'AffiliateID' => '227830',
        ]);
    }

    /**
     * @param  array<string, string|int|null>  $credentials
     * @param  array<string, mixed>  $payload
     */
    private function call(string $provider, string $resource, array $credentials, array $payload = []): Response
    {
        if ($provider !== 'ASPSMS') {
            throw new MobileMessagingException('Unknown SMS provider.');
        }

        $username = $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;
        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            throw new MobileMessagingException('The SMS credentials are incomplete.');
        }

        [$host, $address] = $this->hosts->resolveTarget(self::HOST);
        $curl = [CURLOPT_PROXY => ''];
        if ($host !== $address) {
            $address = str_contains($address, ':') ? "[{$address}]" : $address;
            $curl[CURLOPT_RESOLVE] = ["{$host}:443:{$address}"];
        }

        $response = $this->http->timeout(15)->connectTimeout(5)->withOptions([
            'allow_redirects' => false, 'proxy' => '', 'curl' => $curl,
        ])->post('https://'.self::HOST.'/'.$resource, [
            'UserName' => $username, 'Password' => $password, ...$payload,
        ]);
        if (! $response->successful() || (int) $response->json('StatusCode', 0) !== 1) {
            throw new MobileMessagingException('The SMS provider rejected the request.');
        }

        return $response;
    }
}
