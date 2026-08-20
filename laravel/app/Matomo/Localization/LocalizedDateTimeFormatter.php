<?php

declare(strict_types=1);

namespace App\Matomo\Localization;

use Carbon\CarbonImmutable;
use Throwable;

final readonly class LocalizedDateTimeFormatter
{
    public function __construct(private MatomoTranslator $translator) {}

    public function short(string $value, string $language, bool $uses12HourClock): string
    {
        try {
            $date = CarbonImmutable::parse($value, 'UTC');
        } catch (Throwable) {
            return $value;
        }

        $pattern = $this->translator->translate('Intl_Format_DateTime_Short', $language);
        $timePattern = $this->translator->translate(
            $uses12HourClock ? 'Intl_Format_Time_12' : 'Intl_Format_Time_24',
            $language,
        );
        $formatted = $this->formatPattern(str_replace('{time}', $timePattern, $pattern), $date, $language);

        return mb_strtoupper(mb_substr($formatted, 0, 1)).mb_substr($formatted, 1);
    }

    private function formatPattern(string $pattern, CarbonImmutable $date, string $language): string
    {
        $formatted = '';
        $length = strlen($pattern);

        for ($index = 0; $index < $length;) {
            $character = $pattern[$index];

            if ($character === "'") {
                $end = strpos($pattern, "'", $index + 1);

                if ($end === false) {
                    $formatted .= substr($pattern, $index + 1);
                    break;
                }

                $formatted .= substr($pattern, $index + 1, $end - $index - 1);
                $index = $end + 1;

                continue;
            }

            if (ctype_alpha($character)) {
                $end = $index + 1;

                while ($end < $length && $pattern[$end] === $character) {
                    $end++;
                }

                $formatted .= $this->token(substr($pattern, $index, $end - $index), $date, $language);
                $index = $end;

                continue;
            }

            $formatted .= $character;
            $index++;
        }

        return $formatted;
    }

    private function token(string $token, CarbonImmutable $date, string $language): string
    {
        return match ($token) {
            'y', 'yyyy' => $date->format('Y'),
            'yy' => $date->format('y'),
            'MMMM' => $this->translator->translate(
                'Intl_Month_Long_'.$date->format('n'),
                $language,
            ),
            'MMM' => $this->translator->translate(
                'Intl_Month_Short_'.$date->format('n'),
                $language,
            ),
            'MM' => $date->format('n'),
            'M' => $date->format('m'),
            'dd' => $date->format('d'),
            'd' => $date->format('j'),
            'HH' => $date->format('H'),
            'H' => $date->format('G'),
            'hh' => $date->format('h'),
            'h' => $date->format('g'),
            'KK' => str_pad((string) ((int) $date->format('g') - 1), 2, '0', STR_PAD_LEFT),
            'K' => (string) ((int) $date->format('g') - 1),
            'mm', 'm' => $date->format('i'),
            'ss', 's' => $date->format('s'),
            'a', 'B' => $this->translator->translate(
                $date->format('a') === 'am' ? 'Intl_Time_AM' : 'Intl_Time_PM',
                $language,
            ),
            default => $token,
        };
    }
}
