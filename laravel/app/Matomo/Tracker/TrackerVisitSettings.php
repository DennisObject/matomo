<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

final readonly class TrackerVisitSettings
{
    public function __construct(
        public int $visitStandardLength = 1_800,
        public int $windowLookBackForVisitor = 0,
        public bool $createNewVisitAfterMidnight = true,
        public int $createNewVisitAfterXActions = 10_000,
        public bool $alwaysNewVisitor = false,
        public bool $createNewVisitWhenCampaignChanges = true,
        public bool $createNewVisitWhenWebsiteReferrerChanges = false,
        public bool $trustVisitorCookies = false,
    ) {}

    public function lookBackSeconds(): int
    {
        return max($this->visitStandardLength, $this->windowLookBackForVisitor);
    }
}
