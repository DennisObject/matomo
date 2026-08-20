<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

use App\Matomo\Config\InstallationConfig;

final readonly class ConfiguredDeletionBatchLimits implements DeletionBatchLimits
{
    public function __construct(private InstallationConfig $installation) {}

    public function logs(): int
    {
        return $this->installation->deleteLogsMaxRowsPerQuery();
    }

    public function unusedActions(): int
    {
        return $this->installation->deleteLogsUnusedActionsMaxRowsPerQuery();
    }
}
