<?php

declare(strict_types=1);

namespace App\Matomo\Segments;

interface SegmentEditorSettings
{
    public function allSitesAllowed(): bool;

    public function realtimeAllowed(): bool;

    public function browserTriggerEnabled(): bool;

    public function browserArchivingAvailable(): bool;

    public function processNewSegmentsFrom(): string;
}
