<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

final readonly class StartMatomoUiSession
{
    public function __construct(private StartSession $session) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isReportingApi($request)) {
            return $next($request);
        }

        return $this->session->handle($request, $next);
    }

    private function isReportingApi(Request $request): bool
    {
        $module = (string) $request->input('module', '');
        $method = (string) $request->input('method', '');

        return $module === 'API' || ($module === '' && str_contains($method, '.'));
    }
}
