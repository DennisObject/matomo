<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Users\HttpNewsletterSubscriber;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpNewsletterSubscriberTest extends TestCase
{
    public function test_successful_signup_records_local_option(): void
    {
        Http::fake(['newsletter.test/*' => Http::response('', 200)]);
        $connection = $this->app->make(ConnectionInterface::class);
        $connection->getSchemaBuilder()->create('option', static function (Blueprint $table): void {
            $table->string('option_name')->primary();
            $table->text('option_value');
            $table->unsignedTinyInteger('autoload');
        });
        $subscriber = new HttpNewsletterSubscriber(
            $this->app->make(Factory::class),
            $connection,
            'https://newsletter.test/subscribe',
            true,
            'en',
        );

        $this->assertTrue($subscriber->subscribe('alice', 'alice@example.test'));
        $this->assertSame(
            '1',
            $connection->table('option')->where('option_name', 'UsersManager.newsletterSignup.alice')
                ->value('option_value'),
        );
        Http::assertSent(static fn ($request): bool => $request['email'] === 'alice@example.test');
    }
}
