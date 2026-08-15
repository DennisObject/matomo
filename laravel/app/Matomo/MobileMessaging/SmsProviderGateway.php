<?php

declare(strict_types=1);

namespace App\Matomo\MobileMessaging;

interface SmsProviderGateway
{
    /** @param array<string, string|int|null> $credentials */
    public function verify(string $provider, #[\SensitiveParameter] array $credentials): void;

    /** @param array<string, string|int|null> $credentials */
    public function credit(string $provider, #[\SensitiveParameter] array $credentials): int|string;

    /** @param array<string, string|int|null> $credentials */
    public function send(
        string $provider,
        #[\SensitiveParameter]
        array $credentials,
        string $text,
        string $phoneNumber,
        string $from,
    ): void;
}
