<?php

declare(strict_types=1);

namespace App\Http\Controllers\Matomo;

use App\Http\Controllers\Controller;
use App\Matomo\Login\LogmeSettings;
use App\Matomo\Login\PasswordLoginAuthenticator;
use App\Matomo\Login\UiAuthenticationGate;
use App\Matomo\Login\UiSessionFingerprint;
use App\Matomo\Security\ClientIpResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

final class LoginController extends Controller
{
    public function __construct(
        private readonly PasswordLoginAuthenticator $logins,
        private readonly ClientIpResolver $ips,
        private readonly UiSessionFingerprint $sessions,
        private readonly UiAuthenticationGate $gate,
        private readonly LogmeSettings $logme,
    ) {}

    public function __invoke(Request $request): View|Response|RedirectResponse
    {
        $action = (string) $request->input('action', 'index');
        if ($action === 'logout') {
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/index.php?module=Login');
        }

        if ($action === 'logme') {
            return $this->logme($request);
        }

        if ($this->sessions->login($request->session()) !== null) {
            return redirect($this->gate->home($request));
        }

        if ($request->isMethod('post')) {
            return $this->authenticate($request);
        }

        return $this->form();
    }

    private function form(string $error = '', int $status = 200): View|Response
    {
        $view = view('login', [
            'error' => $error,
            'nonce' => session()->token(),
        ]);

        return $error === '' ? $view : response($view, $status);
    }

    private function authenticate(Request $request): View|Response|RedirectResponse
    {
        $nonce = (string) $request->input('form_nonce');
        if ($nonce === '' || ! hash_equals($request->session()->token(), $nonce)) {
            return $this->form('The form security token is invalid.', 403);
        }

        $result = $this->logins->attempt(
            (string) $request->input('form_login', ''),
            (string) $request->input('form_password', ''),
            $this->ips->resolve($request),
        );

        if (! $result->successful || $result->login === null) {
            return $this->form($result->error, $result->status);
        }

        $remembered = UiSessionFingerprint::wantsRememberMe($request->input('form_rememberme'));
        $request->session()->regenerate();
        $this->sessions->initialize($request->session(), $result->login, $remembered);
        $this->sessions->applyCookieLifetime($remembered);

        return redirect($this->gate->afterLogin($request, $result->login));
    }

    private function logme(Request $request): View|Response|RedirectResponse
    {
        if (! $this->logme->enabled) {
            return response('This functionality has been disabled in config', 403)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $result = $this->logins->attempt(
            (string) $request->input('login', ''),
            (string) $request->input('password', ''),
            $this->ips->resolve($request),
            passwordIsHashed: true,
            rejectSuperUser: true,
        );

        if (! $result->successful || $result->login === null) {
            return $this->form($result->error, $result->status);
        }

        $request->session()->regenerate();
        $this->sessions->initialize($request->session(), $result->login, false);

        return redirect($this->gate->afterLogin($request, $result->login, 'url'));
    }
}
