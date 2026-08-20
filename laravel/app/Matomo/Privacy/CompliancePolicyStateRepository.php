<?php

declare(strict_types=1);

namespace App\Matomo\Privacy;

interface CompliancePolicyStateRepository
{
    public function setActive(?int $idSite, bool $active): void;
}
