<?php

declare(strict_types=1);

namespace Tests\Feature\Matomo;

use App\Matomo\Feedback\DatabaseFeedbackStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class DatabaseFeedbackStoreTest extends TestCase
{
    public function test_reads_user_email_and_updates_only_the_feedback_reminder(): void
    {
        $connection = $this->app->make(ConnectionInterface::class);
        $connection->getSchemaBuilder()->create('user', static function (Blueprint $table): void {
            $table->string('login')->primary();
            $table->string('email');
        });
        $connection->getSchemaBuilder()->create('plugin_setting', static function (Blueprint $table): void {
            $table->string('plugin_name');
            $table->string('user_login');
            $table->string('setting_name');
            $table->text('setting_value');
            $table->unsignedTinyInteger('json_encoded')->default(0);
            $table->unique(['plugin_name', 'user_login', 'setting_name']);
        });
        $connection->table('user')->insert(['login' => 'alice', 'email' => 'alice@example.test']);
        $connection->table('plugin_setting')->insert([
            'plugin_name' => 'Tour',
            'user_login' => 'alice',
            'setting_name' => 'nextFeedbackReminder',
            'setting_value' => 'unchanged',
            'json_encoded' => 0,
        ]);
        $store = new DatabaseFeedbackStore($connection);

        $store->setNextReminder('alice', '2027-02-15');

        $this->assertSame('alice@example.test', $store->emailForLogin('alice'));
        $this->assertSame('', $store->emailForLogin('missing'));
        $this->assertSame([
            ['plugin_name' => 'Feedback', 'setting_value' => '2027-02-15'],
            ['plugin_name' => 'Tour', 'setting_value' => 'unchanged'],
        ], $connection->table('plugin_setting')
            ->orderBy('plugin_name')
            ->get(['plugin_name', 'setting_value'])
            ->map(static fn (object $row): array => (array) $row)
            ->all());
    }
}
