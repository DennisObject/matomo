<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Localization\DatabaseLanguagePreferenceRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseLanguagePreferenceRepositoryTest extends TestCase
{
    public function test_reads_language_with_the_matomo_prefix_and_handles_a_missing_table(): void
    {
        config()->set('database.connections.matomo_language_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_language_test');

        $connection = $databases->connection('matomo_language_test');
        $languages = new DatabaseLanguagePreferenceRepository($connection);

        $this->assertNull($languages->forLogin('alice'));

        $connection->getSchemaBuilder()->create('user_language', function (Blueprint $table): void {
            $table->string('login')->primary();
            $table->string('language');
            $table->boolean('use_12_hour_clock')->default(false);
        });
        $connection->table('user_language')->insert(['login' => 'alice', 'language' => 'fr']);

        $this->assertSame('fr', $languages->forLogin('alice'));
        $this->assertNull($languages->forLogin('bob'));
        $this->assertFalse($languages->uses12HourClock('alice'));
        $this->assertTrue($languages->setLanguage('alice', 'de'));
        $this->assertTrue($languages->set12HourClock('alice', true));
        $this->assertSame('de', $languages->forLogin('alice'));
        $this->assertTrue($languages->uses12HourClock('alice'));
        $this->assertTrue($languages->set12HourClock('bob', true));
        $this->assertSame('', $languages->forLogin('bob'));
    }
}
