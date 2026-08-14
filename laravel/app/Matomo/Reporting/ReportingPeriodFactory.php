<?php

declare(strict_types=1);

namespace App\Matomo\Reporting;

interface ReportingPeriodFactory
{
    /**
     * @return array{list<ReportingPeriod>, bool}
     */
    public function make(string $period, string $date, string $timezone): array;
}
