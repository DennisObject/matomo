<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Privacy\DatabaseAnonymisationSettingsRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

final class DatabaseAnonymisationSettingsRepositoryTest extends TestCase
{
    public function test_site_values_fall_back_and_can_be_replaced_and_removed(): void
    {
        config()->set('database.connections.matomo_anonymisation_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_anonymisation_test');

        $connection = $databases->connection('matomo_anonymisation_test');
        $connection->getSchemaBuilder()->create('option', function (Blueprint $table): void {
            $table->string('option_name')->unique();
            $table->text('option_value');
            $table->boolean('autoload')->default(false);
        });
        $repository = new DatabaseAnonymisationSettingsRepository($connection);
        $repository->replace(null, [
            'ipAddressMaskLength' => 3,
            'ipAnonymizerEnabled' => true,
        ]);

        $this->assertSame(3, $repository->values(7)['ipAddressMaskLength']);
        $this->assertFalse($repository->usesSiteSettings(7));

        $repository->replace(7, [
            'ipAddressMaskLength' => 4,
            'ipAnonymizerEnabled' => false,
        ]);
        $this->assertSame(4, $repository->values(7)['ipAddressMaskLength']);
        $this->assertFalse($repository->values(7)['ipAnonymizerEnabled']);
        $this->assertTrue($repository->usesSiteSettings(7));

        $repository->removeSiteSettings(7);
        $this->assertSame(3, $repository->values(7)['ipAddressMaskLength']);
        $this->assertFalse($repository->usesSiteSettings(7));
    }
}
