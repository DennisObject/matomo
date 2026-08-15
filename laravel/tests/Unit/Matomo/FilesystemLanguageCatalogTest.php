<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Localization\Events\AvailableLanguagesCollecting;
use App\Matomo\Localization\FilesystemLanguageCatalog;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;

class FilesystemLanguageCatalogTest extends TestCase
{
    public function test_lists_configured_languages_names_and_translation_rows(): void
    {
        $events = new Dispatcher;
        $catalog = new FilesystemLanguageCatalog(
            rootDirectory: dirname(__DIR__, 4),
            configuredLanguages: ['en', 'fr'],
            activatedPlugins: ['Intl'],
            bundledPlugins: ['Intl'],
            developmentEnabled: false,
            events: $events,
        );

        $this->assertSame(['en', 'fr'], $catalog->available());
        $this->assertTrue($catalog->isAvailable('fr'));
        $this->assertFalse($catalog->isAvailable('../fr'));
        $this->assertContains('am', $catalog->available(true));
        $this->assertNotContains('dev', $catalog->available(true));
        $this->assertSame([
            ['code' => 'en', 'name' => 'English', 'english_name' => 'English'],
            ['code' => 'fr', 'name' => 'Français', 'english_name' => 'French'],
        ], $catalog->names());

        $translations = $catalog->translations('en');
        $this->assertIsArray($translations);
        $this->assertContains(['label' => 'General_Yes', 'value' => 'Yes'], $translations);
        $this->assertContains(
            ['label' => 'Intl_OriginalLanguageName', 'value' => 'English'],
            $translations,
        );
        $this->assertNull($catalog->translations('not-available'));
    }

    public function test_reports_completion_and_dispatches_the_available_language_event(): void
    {
        $events = new Dispatcher;
        $events->listen(
            AvailableLanguagesCollecting::class,
            static function (AvailableLanguagesCollecting $event): void {
                $event->languages[] = 'zz';
            },
        );
        $catalog = new FilesystemLanguageCatalog(
            rootDirectory: dirname(__DIR__, 4),
            configuredLanguages: ['en'],
            activatedPlugins: [],
            bundledPlugins: ['Intl'],
            developmentEnabled: false,
            events: $events,
        );

        $this->assertSame(['en', 'zz'], $catalog->available());
        $information = $catalog->information();
        $this->assertCount(1, $information);
        $this->assertSame('en', $information[0]['code']);
        $this->assertSame('99%', $information[0]['percentage_complete']);
    }
}
