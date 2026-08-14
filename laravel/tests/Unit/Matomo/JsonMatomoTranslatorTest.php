<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Localization\JsonMatomoTranslator;
use PHPUnit\Framework\TestCase;

class JsonMatomoTranslatorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/matomo-translations-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->directory));
        $this->write('en', [
            'Intl' => [
                'Currency_USD' => 'US Dollar',
                'MovedKey' => 'Moved value',
            ],
            'SitesManager' => ['Format_Utc' => 'UTC%s'],
        ]);
        $this->write('fr', [
            'Intl' => ['Currency_USD' => 'Dollar américain'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*.json') ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_loads_language_fallbacks_moved_keys_and_placeholders(): void
    {
        $translator = new JsonMatomoTranslator([$this->directory]);

        $this->assertSame('Dollar américain', $translator->translate('Intl_Currency_USD', 'fr'));
        $this->assertSame('UTC+2', $translator->translate('SitesManager_Format_Utc', 'fr', ['+2']));
        $this->assertSame('Moved value', $translator->translate('Plugin_MovedKey', 'fr'));
        $this->assertSame('Missing_Key', $translator->translate('Missing_Key', 'fr'));
    }

    /**
     * @param  array<string, array<string, string>>  $translations
     */
    private function write(string $language, array $translations): void
    {
        $content = json_encode($translations, JSON_THROW_ON_ERROR);

        $this->assertSame(
            strlen($content),
            file_put_contents($this->directory."/{$language}.json", $content),
        );
    }
}
