<?php

declare(strict_types=1);

namespace App\Matomo\Tracker;

interface VisitRecorder
{
    public function record(TrackingRequest $request): void;
}
