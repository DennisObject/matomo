<?php

declare(strict_types=1);

namespace App\Matomo\Localization;

use App\Matomo\Authentication\ApiAuthentication;
use Illuminate\Http\Request;

interface LanguageResolver
{
    public function resolve(Request $request, ApiAuthentication $authentication): string;
}
