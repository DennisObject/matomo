<?php

declare(strict_types=1);

namespace App\Matomo\Sites;

interface QueryParameterExclusionPolicy
{
    public function type(?int $idSite = null): string;

    public function parameters(?int $idSite = null): string;
}
