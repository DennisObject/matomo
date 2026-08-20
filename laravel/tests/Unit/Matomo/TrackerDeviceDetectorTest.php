<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Tracker\TrackerDeviceDetector;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class TrackerDeviceDetectorTest extends TestCase
{
    public function test_detects_a_desktop_chrome_browser(): void
    {
        $profile = (new TrackerDeviceDetector)->detect(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            [],
            Request::create('/matomo.php', 'GET', ['pdf' => '1', 'cookie' => '1']),
            1,
            '192.0.2.10',
            'en-us',
            'test-salt',
        );

        $this->assertSame('CH', $profile->browserName);
        $this->assertSame('WIN', $profile->operatingSystem);
        $this->assertFalse($profile->isBot);
        $this->assertTrue($profile->pdf);
        $this->assertSame(8, strlen($profile->configId));
        $this->assertSame('CH', $profile->visitColumns()['config_browser_name']);
        $this->assertSame('WIN', $profile->visitColumns()['config_os']);
        $this->assertSame(1, $profile->visitColumns()['config_pdf']);
    }

    public function test_marks_known_bots(): void
    {
        $profile = (new TrackerDeviceDetector)->detect(
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            [],
            Request::create('/matomo.php'),
            1,
            '192.0.2.10',
            'en',
            'test-salt',
        );

        $this->assertTrue($profile->isBot);
        $this->assertSame('BOT', $profile->operatingSystem);
    }
}
