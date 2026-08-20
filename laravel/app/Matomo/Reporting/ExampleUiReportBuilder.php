<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

use App\Matomo\Api\ApiTableReport;
use Carbon\CarbonImmutable;

final class ExampleUiReportBuilder
{
    /** @var array<string, float> */
    private const array PLANET_RATIOS = [
        'Mercury' => 0.382,
        'Venus' => 0.949,
        'Earth' => 1.00,
        'Mars' => 0.532,
        'Jupiter' => 11.209,
        'Saturn' => 9.449,
        'Uranus' => 4.007,
        'Neptune' => 3.883,
    ];

    public function temperatures(): ApiTableReport
    {
        $values = array_slice(range(50, 90), 0, 24);
        shuffle($values);
        $rows = [];

        foreach ($values as $hour => $value) {
            $rows[] = ['label' => $hour.'h', 'value' => $value];
        }

        return new ApiTableReport($rows, []);
    }

    public function planets(bool $withMetadata, bool $showMetadata): ApiTableReport
    {
        $rows = [];

        foreach (self::PLANET_RATIOS as $planet => $ratio) {
            $row = ['label' => $planet, 'value' => $ratio];

            if ($withMetadata && $showMetadata) {
                $row['logo'] = sprintf(
                    'plugins/ExampleUI/images/icons-planet/%s.png',
                    strtolower($planet),
                );
                $row['url'] = sprintf('http://en.wikipedia.org/wiki/%s', $planet);
            }

            $rows[] = $row;
        }

        return new ApiTableReport($rows, []);
    }

    public function evolution(string $period, string $language): ApiTableReport
    {
        $end = new CarbonImmutable('2013-10-10 00:00:00', 'UTC');
        $count = $period === 'year' ? 10 : 30;
        $rows = [];

        for ($offset = $count - 1; $offset >= 0; $offset--) {
            $date = match ($period) {
                'week' => $end->subWeeks($offset),
                'month' => $end->subMonthsNoOverflow($offset),
                'year' => $end->subYears($offset),
                default => $end->subDays($offset),
            };
            $rows[] = [
                'label' => $this->periodLabel($period, $date, $language),
                'server1' => mt_rand(50, 90),
                'server2' => mt_rand(40, 110),
            ];
        }

        return new ApiTableReport($rows, []);
    }

    private function periodLabel(string $period, CarbonImmutable $date, string $language): string
    {
        $localized = $date->settings(['locale' => $language]);

        return match ($period) {
            'week' => $this->weekLabel($localized),
            'month' => $localized->translatedFormat('M Y'),
            'year' => $localized->format('Y'),
            default => $localized->translatedFormat('D, M j'),
        };
    }

    private function weekLabel(CarbonImmutable $date): string
    {
        $start = $date->startOfWeek();
        $end = $date->endOfWeek();

        if ($start->year !== $end->year) {
            return $start->translatedFormat('M j, Y').' – '.$end->translatedFormat('M j, Y');
        }

        if ($start->month !== $end->month) {
            return $start->translatedFormat('M j').' – '.$end->translatedFormat('M j, Y');
        }

        return $start->translatedFormat('M j').' – '.$end->translatedFormat('j, Y');
    }
}
