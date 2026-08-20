<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\MobileMessaging\MobileMessagingSettingsRepository;
use App\Matomo\MobileMessaging\SmsProviderGateway;
use App\Matomo\Options\MutableOptionRepository;
use Tests\TestCase;

final class MobileMessagingApiTest extends TestCase
{
    private MemoryMobileMessagingSettings $settings;

    private RecordingSmsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new MemoryMobileMessagingSettings;
        $this->provider = new RecordingSmsProvider;
        $this->app->instance(MobileMessagingSettingsRepository::class, $this->settings);
        $this->app->instance(SmsProviderGateway::class, $this->provider);
    }

    public function test_superuser_manages_global_credentials_and_delegation(): void
    {
        $this->bindAccess('admin', true, true);

        $this->post($this->url('setSMSAPICredential'), [
            'provider' => 'ASPSMS', 'credentials' => ['username' => 'user', 'password' => 'secret'],
        ])->assertOk();
        $this->get($this->url('areSMSAPICredentialProvided'))->assertOk()->assertJsonPath('value', true);
        $this->get($this->url('getSMSProvider'))->assertOk()->assertJsonPath('value', 'ASPSMS');
        $this->get($this->url('getCreditLeft'))->assertOk()->assertJsonPath('value', 'Available credits: 17');

        $this->post($this->url('setDelegatedManagement'), ['delegatedManagement' => '1'])->assertOk();
        $this->get($this->url('getDelegatedManagement'))->assertOk()->assertJsonPath('value', true);
        $this->post($this->url('deleteSMSAPICredential'))->assertOk();

        $this->assertSame('secret', $this->provider->verifiedCredentials['password']);
        $this->assertNull($this->settings->values['admin']['APIKey'] ?? null);
    }

    public function test_phone_number_verification_lifecycle_preserves_private_codes(): void
    {
        $this->app->make(MutableOptionRepository::class)->set('MobileMessaging_DelegatedManagement', '1');
        $this->settings->values['member'] = ['Provider' => 'Development', 'APIKey' => []];
        $this->bindAccess('member', false, true);

        $this->post($this->url('addPhoneNumber'), ['phoneNumber' => '+64 (21) 123-456'])
            ->assertOk();
        $numbers = $this->get($this->url('getPhoneNumbers'))->assertOk()->json();
        $this->assertArrayHasKey('+6421123456', $numbers);
        $this->assertArrayNotHasKey('verificationCode', $numbers['+6421123456']);
        preg_match('/([A-Za-z0-9]{6})$/', $this->provider->lastText, $matches);
        $code = $matches[1] ?? self::fail('The verification message did not contain a code.');

        $this->post($this->url('validatePhoneNumber'), [
            'phoneNumber' => '+6421123456', 'verificationCode' => $code,
        ])->assertOk()->assertJsonPath('value', true);
        $this->post($this->url('removePhoneNumber'), ['phoneNumber' => '+6421123456'])->assertOk();
        $this->assertSame([], $this->get($this->url('getPhoneNumbers'))->assertOk()->json());
    }

    public function test_resend_enforces_the_cooldown_and_bad_codes_stop_after_three_attempts(): void
    {
        $this->app->make(MutableOptionRepository::class)->set('MobileMessaging_DelegatedManagement', '1');
        $this->settings->values['member'] = ['Provider' => 'Development', 'APIKey' => []];
        $this->bindAccess('member', false, true);
        $this->post($this->url('addPhoneNumber'), ['phoneNumber' => '+1234567'])->assertOk();

        $this->post($this->url('resendVerificationCode'), ['phoneNumber' => '+1234567'])
            ->assertBadRequest()->assertJsonPath('message', 'A verification code was sent recently.');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post($this->url('validatePhoneNumber'), [
                'phoneNumber' => '+1234567', 'verificationCode' => 'wrong-code',
            ])->assertOk()->assertJsonPath('value', false);
        }

        $this->assertNull($this->settings->values['member']['PhoneNumbers']['+1234567']['verificationCode']);
    }

    public function test_rejects_anonymous_phone_access_and_non_superuser_global_management(): void
    {
        $this->bindAccess('anonymous', false, false);
        $this->get($this->url('getPhoneNumbers'))->assertUnauthorized();

        $this->bindAccess('member', false, true);
        $this->post($this->url('setSMSAPICredential'), [
            'provider' => 'Development', 'credentials' => [],
        ])->assertUnauthorized();
        $this->post($this->url('setDelegatedManagement'), ['delegatedManagement' => 1])->assertUnauthorized();
    }

    private function bindAccess(string $login, bool $superUser, bool $someView): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn($login);
        $authorizer->method('hasSuperUserAccess')->willReturn($superUser);
        $authorizer->method('hasSomeViewAccess')->willReturn($someView);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    private function url(string $method): string
    {
        return "/index.php?module=API&method=MobileMessaging.{$method}&format=json&token_auth=test-token";
    }
}

final class MemoryMobileMessagingSettings implements MobileMessagingSettingsRepository
{
    /** @var array<string, array<string, mixed>> */
    public array $values = [];

    public function read(string $login): array
    {
        return $this->values[$login] ?? [];
    }

    public function save(string $login, #[\SensitiveParameter] array $settings): void
    {
        $this->values[$login] = $settings;
    }
}

final class RecordingSmsProvider implements SmsProviderGateway
{
    /** @var array<string, string|int|null> */
    public array $verifiedCredentials = [];

    public string $lastText = '';

    public function verify(string $provider, #[\SensitiveParameter] array $credentials): void
    {
        $this->verifiedCredentials = $credentials;
    }

    public function credit(string $provider, #[\SensitiveParameter] array $credentials): int|string
    {
        return $provider === 'ASPSMS' ? 17 : 'unlimited';
    }

    public function send(
        string $provider,
        #[\SensitiveParameter]
        array $credentials,
        string $text,
        string $phoneNumber,
        string $from,
    ): void {
        $this->lastText = $text;
    }
}
