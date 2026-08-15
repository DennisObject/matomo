<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Localization\MatomoTranslator;
use App\Matomo\Plugins\PluginState;
use App\Matomo\Sites\DatabaseSiteSettingsProvider;
use App\Matomo\Sites\SiteRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseSiteSettingsProviderTest extends TestCase
{
    public function test_formats_core_and_activated_plugin_settings_from_storage(): void
    {
        config()->set('database.connections.matomo_site_settings_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_site_settings_test');

        $connection = $databases->connection('matomo_site_settings_test');
        $connection->getSchemaBuilder()->create('site_setting', function (Blueprint $table): void {
            $table->unsignedInteger('idsite');
            $table->string('plugin_name');
            $table->string('setting_name');
            $table->text('setting_value');
            $table->boolean('json_encoded')->default(false);
        });
        $connection->table('site_setting')->insert([
            ['idsite' => 7, 'plugin_name' => 'Live', 'setting_name' => 'disable_visitor_log', 'setting_value' => 'true', 'json_encoded' => 1],
            ['idsite' => 7, 'plugin_name' => 'ExampleSettingsPlugin', 'setting_name' => 'contact_email', 'setting_value' => '["admin@example.test"]', 'json_encoded' => 1],
        ]);
        $sites = $this->createStub(SiteRepository::class);
        $sites->method('details')->willReturn([
            'exclude_unknown_urls' => 1,
            'keep_url_fragment' => 2,
            'sitesearch' => 1,
            'sitesearch_keyword_parameters' => 'q,search',
            'sitesearch_category_parameters' => 'category',
            'excluded_ips' => '127.0.0.1,10.0.0.0/8',
            'excluded_parameters' => 'token',
            'excluded_user_agents' => 'crawler',
            'excluded_referrers' => 'ignored.test',
            'ecommerce' => 1,
        ]);
        $sites->method('urls')->willReturn(['https://example.test', 'https://alias.test']);
        $plugins = $this->createStub(PluginState::class);
        $plugins->method('isActivated')->willReturnMap([
            ['Live', true],
            ['ExampleSettingsPlugin', true],
        ]);
        $translator = $this->createStub(MatomoTranslator::class);
        $translator->method('translate')->willReturnCallback(
            static fn (string $key): string => $key,
        );
        $provider = new DatabaseSiteSettingsProvider($connection, $sites, $plugins, $translator);

        $metadata = $provider->metadata(7, 'en');

        $this->assertSame(['WebsiteMeasurable', 'Live', 'ExampleSettingsPlugin'], array_column($metadata, 'pluginName'));
        $this->assertSame(['https://example.test', 'https://alias.test'], $metadata[0]['settings'][0]['value']);
        $this->assertSame(['127.0.0.1', '10.0.0.0/8'], $metadata[0]['settings'][3]['value']);
        $this->assertTrue($metadata[1]['settings'][0]['value']);
        $this->assertSame(['admin@example.test'], $metadata[2]['settings'][0]['value']);
    }
}
