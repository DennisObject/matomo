<?php

declare(strict_types=1);

namespace App\Matomo\Api\Methods;

use App\Matomo\Api\ApiRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

interface ApiMethodHandler
{
    public function supports(ApiRequest $request): bool;

    public function handle(ApiRequest $request, Request $httpRequest): Response;
}
