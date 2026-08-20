<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Options\MutableOptionRepository;
use App\Matomo\Tracker\TrackerInstallationCheck;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class TrackerInstallationCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_reuses_a_current_nonce_and_preserves_success(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 12:00:00 UTC');
        $options = $this->options();
        $checks = new TrackerInstallationCheck($options);
        $first = $checks->initiate(7, 'https://example.test/path');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $first['nonce']);
        $this->assertTrue($checks->markSuccessful(7, $first['nonce']));

        CarbonImmutable::setTestNow('2026-08-15 12:00:10 UTC');
        $second = $checks->initiate(7, 'https://example.test/path');

        $this->assertSame($first['nonce'], $second['nonce']);
        $this->assertTrue($checks->successful(7, $second['nonce'], ''));
    }

    public function test_expires_nonce_checks_and_creates_a_replacement(): void
    {
        CarbonImmutable::setTestNow('2026-08-15 12:00:00 UTC');
        $checks = new TrackerInstallationCheck($this->options());
        $first = $checks->initiate(7, 'https://example.test');

        CarbonImmutable::setTestNow('2026-08-15 12:00:31 UTC');
        $this->assertFalse($checks->successful(7, $first['nonce'], ''));
        $second = $checks->initiate(7, 'https://example.test');

        $this->assertNotSame($first['nonce'], $second['nonce']);
        $this->assertFalse($checks->markSuccessful(7, $first['nonce']));
    }

    public function test_without_nonce_prefers_the_result_for_the_main_url(): void
    {
        $nonceOne = str_repeat('a', 32);
        $nonceTwo = str_repeat('b', 32);
        $options = $this->options([
            'JsTrackerInstallCheck_7' => json_encode([
                $nonceOne => [
                    'time' => 1,
                    'url' => 'https://other.example',
                    'isSuccessful' => false,
                ],
                $nonceTwo => [
                    'time' => 1,
                    'url' => 'https://main.example',
                    'isSuccessful' => true,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->assertTrue((new TrackerInstallationCheck($options))->successful(
            7,
            '',
            'https://main.example',
        ));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function options(array $values = []): MutableOptionRepository
    {
        return new class($values) implements MutableOptionRepository
        {
            /** @param array<string, string> $values */
            public function __construct(private array $values) {}

            public function value(string $name): ?string
            {
                return $this->values[$name] ?? null;
            }

            public function set(string $name, string $value, bool $autoload = false): void
            {
                $this->values[$name] = $value;
            }

            public function delete(string $name): void
            {
                unset($this->values[$name]);
            }
        };
    }
}
