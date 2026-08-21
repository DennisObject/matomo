<?php

declare(strict_types=1);

namespace App\Http\Controllers\Matomo;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Matomo\Api\ReportingApiController;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class FrontController extends Controller
{
    public function __construct(private readonly Container $container) {}

    public function __invoke(Request $request): Response|View
    {
        $module = (string) $request->input('module', '');
        $method = (string) $request->input('method', '');

        if ($module === 'API' || ($module === '' && str_contains($method, '.'))) {
            return $this->container->make(ReportingApiController::class)->__invoke($request);
        }

        if ($module === 'Login' || $module === '') {
            return $this->container->make(LoginController::class)->__invoke($request);
        }

        if ($module === 'CoreHome') {
            return $this->container->make(CoreHomeController::class)->__invoke($request);
        }

        if ($module === 'TwoFactorAuth') {
            return $this->container->make(TwoFactorAuthController::class)->__invoke($request);
        }

        return response('This UI module has not moved to Laravel yet.', 501)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
