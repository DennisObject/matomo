<?php

declare(strict_types=1);

namespace App\Matomo\MobileMessaging;

use App\Matomo\Options\MutableOptionRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;

final readonly class MobileMessagingManager
{
    private const string DELEGATED_OPTION = 'MobileMessaging_DelegatedManagement';

    public function __construct(
        private MobileMessagingSettingsRepository $settings,
        private MutableOptionRepository $options,
        private SmsProviderGateway $providers,
        private Dispatcher $events,
    ) {}

    public function delegatedManagement(): bool
    {
        return in_array($this->options->value(self::DELEGATED_OPTION), ['1', 'true'], true);
    }

    public function setDelegatedManagement(bool $enabled): void
    {
        $this->options->set(self::DELEGATED_OPTION, $enabled ? '1' : '0');
    }

    public function credentialsProvided(string $login): bool
    {
        $settings = $this->credentialSettings($login);

        return is_array($settings['APIKey'] ?? null);
    }

    public function provider(string $login): ?string
    {
        $provider = $this->credentialSettings($login)['Provider'] ?? null;

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    /** @param array<string, string|int|null> $credentials */
    public function setCredentials(string $login, string $provider, #[\SensitiveParameter] array $credentials): void
    {
        $this->providers->verify($provider, $credentials);
        $settings = $this->credentialSettings($login);
        $settings['Provider'] = $provider;
        $settings['APIKey'] = $credentials;
        $this->settings->save($this->credentialLogin($login), $settings);
    }

    public function deleteCredentials(string $login): void
    {
        $settings = $this->credentialSettings($login);
        $settings['APIKey'] = null;
        $this->settings->save($this->credentialLogin($login), $settings);
    }

    public function credit(string $login): int|string
    {
        [$provider, $credentials] = $this->credential($login);

        return $this->providers->credit($provider, $credentials);
    }

    /** @param list<string> $phoneNumbers */
    public function sendMessage(string $login, string $message, array $phoneNumbers): void
    {
        [$provider, $credentials] = $this->credential($login);
        $verified = array_keys(array_filter(
            $this->storedPhoneNumbers($login),
            static fn (array $data): bool => $data['verified'],
        ));
        foreach (array_values(array_unique($phoneNumbers)) as $phoneNumber) {
            $phoneNumber = $this->phoneNumber($phoneNumber);
            if (! in_array($phoneNumber, $verified, true)) {
                throw new MobileMessagingException('A scheduled report phone number is not verified.');
            }

            $this->providers->send($provider, $credentials, $message, $phoneNumber, 'Matomo');
        }
    }

    /** @return array<string, array{verified: bool, verificationTries: int, verificationTime: int|null, requestTime: int}> */
    public function phoneNumbers(string $login): array
    {
        $numbers = $this->storedPhoneNumbers($login);
        $public = [];
        foreach ($numbers as $phoneNumber => $data) {
            unset($data['verificationCode']);
            $public[$phoneNumber] = $data;
        }

        uasort($public, static function (array $left, array $right): int {
            if ($left['verified'] !== $right['verified']) {
                return $left['verified'] <=> $right['verified'];
            }

            return ($right[$left['verified'] ? 'verificationTime' : 'requestTime'] ?? 0)
                <=> ($left[$left['verified'] ? 'verificationTime' : 'requestTime'] ?? 0);
        });

        return $public;
    }

    public function addPhoneNumber(string $login, string $phoneNumber): void
    {
        $phoneNumber = $this->phoneNumber($phoneNumber);
        $numbers = $this->storedPhoneNumbers($login);
        if (isset($numbers[$phoneNumber])) {
            throw new MobileMessagingException("The phone number {$phoneNumber} has already been added.");
        }

        if (count(array_filter($numbers, static fn (array $data): bool => ! $data['verified'])) >= 3) {
            throw new MobileMessagingException('Too many unverified phone numbers.');
        }

        $this->sendVerification($login, $phoneNumber, $numbers);
    }

    public function resendVerificationCode(string $login, string $phoneNumber): void
    {
        $phoneNumber = $this->phoneNumber($phoneNumber);
        $numbers = $this->storedPhoneNumbers($login);
        if (! isset($numbers[$phoneNumber])) {
            throw new MobileMessagingException("The phone number {$phoneNumber} has not yet been added.");
        }

        if ($numbers[$phoneNumber]['verified']) {
            throw new MobileMessagingException("The phone number {$phoneNumber} has already been verified.");
        }

        if ($numbers[$phoneNumber]['requestTime'] > time() - 60) {
            throw new MobileMessagingException('A verification code was sent recently.');
        }

        $this->sendVerification($login, $phoneNumber, $numbers);
    }

    public function removePhoneNumber(string $login, string $phoneNumber): void
    {
        $phoneNumber = $this->phoneNumber($phoneNumber);
        $settings = $this->settings->read($login);
        $numbers = $this->storedPhoneNumbers($login);
        unset($numbers[$phoneNumber]);
        $settings['PhoneNumbers'] = $numbers;
        $this->settings->save($login, $settings);
        $this->events->dispatch(new PhoneNumberDeleted($phoneNumber));
    }

    public function validatePhoneNumber(string $login, string $phoneNumber, #[\SensitiveParameter] string $code): bool
    {
        $phoneNumber = $this->phoneNumber($phoneNumber);
        $settings = $this->settings->read($login);
        $numbers = $this->storedPhoneNumbers($login);
        if (! isset($numbers[$phoneNumber])) {
            return false;
        }

        if ($numbers[$phoneNumber]['verified']) {
            return true;
        }

        if ($numbers[$phoneNumber]['requestTime'] < time() - 600) {
            $numbers[$phoneNumber]['verificationCode'] = null;
        } elseif (! hash_equals($numbers[$phoneNumber]['verificationCode'] ?? '', $code)) {
            $numbers[$phoneNumber]['verificationTries']++;
            if ($numbers[$phoneNumber]['verificationTries'] >= 3) {
                $numbers[$phoneNumber]['verificationCode'] = null;
            }
        } else {
            $numbers[$phoneNumber]['verified'] = true;
            $numbers[$phoneNumber]['verificationCode'] = null;
            $numbers[$phoneNumber]['verificationTries'] = 0;
            $numbers[$phoneNumber]['verificationTime'] = time();
        }

        $settings['PhoneNumbers'] = $numbers;
        $this->settings->save($login, $settings);

        return $numbers[$phoneNumber]['verified'];
    }

    /** @param array<string, array{verified: bool, verificationCode: string|null, verificationTries: int, verificationTime: int|null, requestTime: int}> $numbers */
    private function sendVerification(string $login, string $phoneNumber, array $numbers): void
    {
        [$provider, $credentials] = $this->credential($login);
        $code = Str::random(6);
        $this->providers->send($provider, $credentials, "Verification code: {$code}", $phoneNumber, 'Matomo');
        $numbers[$phoneNumber] = ['verified' => false, 'verificationCode' => $code,
            'verificationTries' => 0, 'verificationTime' => null, 'requestTime' => time()];
        $settings = $this->settings->read($login);
        $settings['PhoneNumbers'] = $numbers;
        $this->settings->save($login, $settings);
    }

    /** @return array{string, array<string, string|int|null>} */
    private function credential(string $login): array
    {
        $settings = $this->credentialSettings($login);
        $provider = $settings['Provider'] ?? null;
        $credentials = $settings['APIKey'] ?? null;
        if (! is_string($provider) || $provider === '' || ! is_array($credentials)) {
            throw new MobileMessagingException('No SMS provider configured.');
        }

        return [$provider, array_filter($credentials, static fn (mixed $value): bool => is_string($value) || is_int($value) || $value === null)];
    }

    /** @return array<string, mixed> */
    private function credentialSettings(string $login): array
    {
        return $this->settings->read($this->credentialLogin($login));
    }

    private function credentialLogin(string $login): string
    {
        return $this->delegatedManagement() ? $login : '';
    }

    /** @return array<string, array{verified: bool, verificationCode: string|null, verificationTries: int, verificationTime: int|null, requestTime: int}> */
    private function storedPhoneNumbers(string $login): array
    {
        $stored = $this->settings->read($login)['PhoneNumbers'] ?? [];
        if (! is_array($stored)) {
            return [];
        }

        $numbers = [];
        foreach ($stored as $phone => $data) {
            if (! is_string($phone)) {
                continue;
            }

            $data = is_array($data) ? $data : [];
            $numbers[$phone] = ['verified' => ! empty($data['verified']),
                'verificationCode' => is_string($data['verificationCode'] ?? null) ? $data['verificationCode'] : null,
                'verificationTries' => (int) ($data['verificationTries'] ?? 0),
                'verificationTime' => isset($data['verificationTime']) ? (int) $data['verificationTime'] : null,
                'requestTime' => (int) ($data['requestTime'] ?? time())];
        }

        return $numbers;
    }

    private function phoneNumber(string $phoneNumber): string
    {
        $phoneNumber = str_replace(['-', '_', ' ', '(', ')'], '', $phoneNumber);
        if (strlen($phoneNumber) > 100 || preg_match('/^\+[0-9]{5,30}$/D', $phoneNumber) !== 1) {
            throw new MobileMessagingException('The phone number format is invalid.');
        }

        return $phoneNumber;
    }
}
