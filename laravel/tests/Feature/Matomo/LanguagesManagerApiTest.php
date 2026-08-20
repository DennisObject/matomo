<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Authentication\ApiAccessAuthorizer;
use App\Matomo\Localization\LanguageCatalog;
use Tests\TestCase;

class LanguagesManagerApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticate('anonymous');
    }

    public function test_returns_public_language_catalog_data(): void
    {
        $catalog = $this->createStub(LanguageCatalog::class);
        $catalog->method('available')->willReturn(['en', 'fr']);
        $catalog->method('isAvailable')->willReturn(true);
        $catalog->method('names')->willReturn([
            ['code' => 'fr', 'name' => 'Français', 'english_name' => 'French'],
        ]);
        $catalog->method('information')->willReturn([
            [
                'code' => 'fr',
                'name' => 'Français',
                'english_name' => 'French',
                'translators' => 'A Translator',
                'percentage_complete' => '99%',
            ],
        ]);
        $catalog->method('translations')->willReturn([
            ['label' => 'General_Yes', 'value' => 'Oui'],
        ]);
        $this->app->instance(LanguageCatalog::class, $catalog);

        $this->get($this->url('getAvailableLanguages'))
            ->assertOk()->assertExactJson(['en', 'fr']);
        $this->get($this->url('isLanguageAvailable', ['languageCode' => 'fr']))
            ->assertOk()->assertExactJson(['value' => true]);
        $this->get($this->url('getAvailableLanguageNames'))
            ->assertOk()->assertJsonPath('0.english_name', 'French');
        $this->get($this->url('getAvailableLanguagesInfo'))
            ->assertOk()->assertJsonPath('0.percentage_complete', '99%');
        $this->get($this->url('getTranslationsForLanguage', ['languageCode' => 'fr']))
            ->assertOk()->assertExactJson([
                ['label' => 'General_Yes', 'value' => 'Oui'],
            ]);
    }

    public function test_user_can_update_and_read_own_preferences(): void
    {
        $this->authenticate('alice');
        $catalog = $this->createStub(LanguageCatalog::class);
        $catalog->method('isAvailable')->with('fr')->willReturn(true);
        $this->app->instance(LanguageCatalog::class, $catalog);

        $this->post($this->url('setLanguageForUser', [
            'login' => 'alice',
            'languageCode' => 'fr',
        ]))->assertOk()->assertExactJson(['value' => true]);
        $this->get($this->url('getLanguageForUser', ['login' => 'alice']))
            ->assertOk()->assertExactJson(['value' => 'fr']);
        $this->post($this->url('set12HourClockForUser', [
            'login' => 'alice',
            'use12HourClock' => '1',
        ]))->assertOk()->assertExactJson(['value' => true]);
        $this->get($this->url('uses12HourClockForUser', ['login' => 'alice']))
            ->assertOk()->assertExactJson(['value' => true]);
    }

    public function test_anonymous_special_cases_and_user_access_are_preserved(): void
    {
        $this->get($this->url('getLanguageForUser', ['login' => 'anonymous']))
            ->assertOk()->assertExactJson(['value' => false]);
        $this->post($this->url('set12HourClockForUser', [
            'login' => 'anonymous',
            'use12HourClock' => '1',
        ]))->assertOk()->assertExactJson(['value' => false]);

        $this->authenticate('alice');
        $this->get($this->url('getLanguageForUser', ['login' => 'bob']))
            ->assertStatus(401)
            ->assertJsonPath(
                'message',
                "The user has to be either a Super User or the user 'bob' itself.",
            );
    }

    public function test_rejects_missing_required_parameters(): void
    {
        $this->get($this->url('isLanguageAvailable'))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'languageCode'.");
        $this->post($this->url('set12HourClockForUser', ['login' => 'alice']))
            ->assertBadRequest()
            ->assertJsonPath('message', "Please specify a value for 'use12HourClock'.");
    }

    private function authenticate(string $login): void
    {
        $authorizer = $this->createStub(ApiAccessAuthorizer::class);
        $authorizer->method('authenticatedLogin')->willReturn($login);
        $authorizer->method('hasSuperUserAccess')->willReturn(false);
        $this->app->instance(ApiAccessAuthorizer::class, $authorizer);
    }

    /** @param array<string, string> $parameters */
    private function url(string $method, array $parameters = []): string
    {
        return '/index.php?'.http_build_query([
            'module' => 'API',
            'method' => 'LanguagesManager.'.$method,
            'format' => 'json',
            ...$parameters,
        ]);
    }
}
