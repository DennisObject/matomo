<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

interface DeletionBatchLimits
{
    public function logs(): int;

    public function unusedActions(): int;
}
