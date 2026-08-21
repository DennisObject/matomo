<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Matomo\Login\UiSessionFingerprint;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

final readonly class StartMatomoUiSession
{
    public function __construct(
        private StartSession $session,
        private UiSessionFingerprint $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isReportingApi($request)) {
            return $next($request);
        }

        if ($this->shouldRememberMe($request)) {
            $this->sessions->applyCookieLifetime(true);
        }

        return $this->session->handle($request, function (Request $request) use ($next): Response {
            if (! $request->hasSession()) {
                return $next($request);
            }

            if (! $this->sessions->maintain($request->session())) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            } elseif (is_string($request->session()->get('matomo.login'))) {
                $this->sessions->applyCookieLifetime($this->sessions->remembered($request->session()));
            }

            return $next($request);
        });
    }

    private function shouldRememberMe(Request $request): bool
    {
        if (! $request->isMethod('post')
            || ! UiSessionFingerprint::wantsRememberMe($request->input('form_rememberme'))) {
            return false;
        }

        $module = (string) $request->input('module', '');
        $action = (string) $request->input('action', '');

        return in_array($module, ['', 'Login', 'CoreHome'], true)
            && ($action === '' || in_array($action, ['index', 'login'], true));
    }

    private function isReportingApi(Request $request): bool
    {
        $module = (string) $request->input('module', '');
        $method = (string) $request->input('method', '');

        return $module === 'API' || ($module === '' && str_contains($method, '.'));
    }
}
