<?php

declare(strict_types=1);

namespace App\Matomo\Security;

use Illuminate\Http\Request;

interface ReportingApiIpAllowlist
{
    public function deniedClientIp(Request $request): ?string;
}
