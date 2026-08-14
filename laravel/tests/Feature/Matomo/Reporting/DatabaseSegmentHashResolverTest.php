<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo\Reporting;

use App\Matomo\Reporting\DatabaseSegmentHashResolver;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseSegmentHashResolverTest extends TestCase
{
    public function test_uses_a_stored_hash_for_encoded_or_decoded_definitions(): void
    {
        config()->set('database.connections.matomo_segment_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'matomo_',
            'foreign_key_constraints' => true,
        ]);
        $databases = $this->app->make(DatabaseManager::class);
        $databases->purge('matomo_segment_test');

        $connection = $databases->connection('matomo_segment_test');
        $connection->getSchemaBuilder()->create('segment', function (Blueprint $table): void {
            $table->text('definition');
            $table->char('hash', 32)->nullable();
        });
        $connection->table('segment')->insert([
            'definition' => 'countryCode==NZ',
            'hash' => '0123456789abcdef0123456789abcdef',
        ]);
        $resolver = new DatabaseSegmentHashResolver($connection);

        $this->assertSame('', $resolver->resolve(null));
        $this->assertSame(
            '0123456789abcdef0123456789abcdef',
            $resolver->resolve('countryCode%3D%3DNZ'),
        );
        $this->assertSame(md5('browserCode==FF'), $resolver->resolve('browserCode==FF'));
    }
}
