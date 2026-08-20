<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

final class DurationFormatter
{
    public function sentence(float|int|string|null $value): string
    {
        $seconds = (float) ($value ?? 0);
        $negative = $seconds < 0;
        $seconds = abs($seconds);
        $secondsInYear = 86400 * 365.25;
        $years = floor($seconds / $secondsInYear);
        $withoutYears = $seconds - $years * $secondsInYear;
        $days = floor($withoutYears / 86400);
        $withoutDays = $seconds - $days * 86400;
        $hours = floor($withoutDays / 3600);
        $withoutHours = $withoutDays - $hours * 3600;
        $minutes = floor($withoutHours / 60);
        $remainingSeconds = $withoutHours - $minutes * 60;
        $precision = $remainingSeconds > 0 && $remainingSeconds < 0.01 ? 3 : 2;
        $remainingSeconds = rtrim(rtrim(number_format(round($remainingSeconds, $precision), $precision, '.', ''), '0'), '.');
        $result = match (true) {
            $years > 0 => "{$years} years {$days} days",
            $days > 0 => "{$days} days {$hours} hours",
            $hours > 0 => "{$hours} hours {$minutes} min",
            $minutes > 0 => "{$minutes} min {$remainingSeconds}s",
            default => "{$remainingSeconds}s",
        };

        return $negative ? '-'.$result : $result;
    }
}
