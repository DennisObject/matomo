<?php

declare(strict_types=1);

namespace App\Matomo\Archiving;

interface CronArchiveRunner
{
    public function run(): CronArchiveRunResult;
}
