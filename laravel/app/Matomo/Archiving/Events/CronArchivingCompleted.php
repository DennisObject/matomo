<?php

declare(strict_types=1);

namespace App\Matomo\Archiving\Events;

use App\Matomo\Archiving\CronArchiveRunResult;

final readonly class CronArchivingCompleted
{
    public function __construct(public CronArchiveRunResult $result) {}
}
