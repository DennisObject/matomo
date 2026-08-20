<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Localization\LocalizedDateTimeFormatter;
use App\Matomo\Localization\MatomoTranslator;
use PHPUnit\Framework\TestCase;

class LocalizedDateTimeFormatterTest extends TestCase
{
    public function test_formats_short_dates_with_24_and_12_hour_clocks(): void
    {
        $formatter = new LocalizedDateTimeFormatter($this->translator());

        $this->assertSame(
            'Aug 15, 2026 18:37:09',
            $formatter->short('2026-08-15 18:37:09', 'en', false),
        );
        $this->assertSame(
            "Aug 15, 2026 6:37:09\u{202F}PM",
            $formatter->short('2026-08-15 18:37:09', 'en', true),
        );
    }

    public function test_preserves_an_invalid_date(): void
    {
        $formatter = new LocalizedDateTimeFormatter($this->translator());

        $this->assertSame('not-a-date', $formatter->short('not-a-date', 'en', false));
    }

    private function translator(): MatomoTranslator
    {
        return new class implements MatomoTranslator
        {
            public function translate(string $key, string $language, array $arguments = []): string
            {
                return match ($key) {
                    'Intl_Format_DateTime_Short' => 'MMM d, y {time}',
                    'Intl_Format_Time_12' => "h:mm:ss\u{202F}a",
                    'Intl_Format_Time_24' => 'HH:mm:ss',
                    'Intl_Month_Short_8' => 'Aug',
                    'Intl_Time_PM' => 'PM',
                    default => $key,
                };
            }
        };
    }
}
