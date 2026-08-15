<?php

declare(strict_types=1);

namespace App\Matomo\Api;

final readonly class ScheduledReportsRequest
{
    /**
     * @param  list<string>  $reports
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(
        public ?int $idReport = null,
        public ?int $idSite = null,
        public ?string $description = null,
        public ?string $period = null,
        public ?int $hour = null,
        public ?string $reportType = null,
        public ?string $reportFormat = null,
        public array $reports = [],
        public array $parameters = [],
        public ?int $idSegment = null,
        public string $evolutionPeriodFor = 'prev',
        public ?int $evolutionPeriodN = null,
        public ?string $periodParam = null,
        public bool $onlyOwnReports = false,
        public ?int $dashboardId = null,
        public string $segment = '',
        public ?string $date = null,
        public ?string $language = null,
        public ?int $outputType = null,
        public bool $force = false,
    ) {}
}
