<?php

declare(strict_types=1);

namespace App\Http\Controllers\Matomo;

use App\Http\Controllers\Controller;
use App\Matomo\Login\LoginAttemptGuard;
use App\Matomo\Login\UiAuthenticationGate;
use App\Matomo\Login\UiSessionFingerprint;
use App\Matomo\Security\ClientIpResolver;
use App\Matomo\TwoFactorAuth\TwoFactorCodeVerifier;
use App\Matomo\TwoFactorAuth\TwoFactorUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

final class TwoFactorAuthController extends Controller
{
    public function __construct(
        private readonly UiSessionFingerprint $sessions,
        private readonly UiAuthenticationGate $gate,
        private readonly TwoFactorUser $users,
        private readonly TwoFactorCodeVerifier $codes,
        private readonly LoginAttemptGuard $attempts,
        private readonly ClientIpResolver $ips,
    ) {}

    public function __invoke(Request $request): View|Response|RedirectResponse
    {
        $login = $this->sessions->login($request->session());
        if ($login === null) {
            return redirect('/index.php?module=Login');
        }

        if (! $this->users->isEnabled($login)) {
            return redirect($this->gate->home($request));
        }

        if ($this->sessions->hasVerifiedTwoFactor($request->session())) {
            return redirect('/index.php?module=CoreHome&action=index');
        }

        if ($request->isMethod('post')) {
            return $this->verify($request, $login);
        }

        return $this->form();
    }

    private function form(string $error = '', int $status = 200): View|Response
    {
        $view = view('two-factor-login', [
            'error' => $error,
            'nonce' => session()->token(),
        ]);

        return $error === '' ? $view : response($view, $status);
    }

    private function verify(Request $request, string $login): View|Response|RedirectResponse
    {
        $nonce = (string) $request->input('form_nonce');
        if ($nonce === '' || ! hash_equals($request->session()->token(), $nonce)) {
            return $this->form('The form security token is invalid.', 403);
        }

        $code = (string) $request->input('form_authcode', '');
        if ($this->codes->verify($login, $code)) {
            $this->sessions->setTwoFactorVerified($request->session(), $login);

            return redirect('/index.php?module=CoreHome&action=index');
        }

        $this->attempts->recordFailure($this->ips->resolve($request), $login);

        return $this->form('Incorrect two-factor authentication code.', 403);
    }
}
