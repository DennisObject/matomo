<?php

declare(strict_types=1);

namespace App\Matomo\ScheduledReports;

interface ScheduledReportSender
{
    /** @param list<string> $recipients */
    public function send(
        array $recipients,
        string $subject,
        string $html,
        ?RenderedScheduledReport $attachment = null,
    ): void;
}
