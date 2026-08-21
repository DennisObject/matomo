<?php

declare(strict_types=1);

namespace App\Matomo\Login;

use App\Matomo\TwoFactorAuth\TwoFactorUser;
use Illuminate\Http\Request;

final readonly class UiAuthenticationGate
{
    public function __construct(
        private TwoFactorUser $twoFactor,
        private UiSessionFingerprint $sessions,
        private TrustedLoginRedirect $redirects,
    ) {}

    public function home(Request $request): string
    {
        $login = $this->sessions->login($request->session());
        if ($login === null) {
            return '/index.php?module=Login';
        }

        if ($this->requiresTwoFactor($request, $login)) {
            return '/index.php?module=TwoFactorAuth&action=loginTwoFactorAuth';
        }

        return '/index.php?module=CoreHome&action=index';
    }

    public function afterLogin(Request $request, string $login, string $redirectParameter = 'form_redirect'): string
    {
        if ($this->twoFactor->isEnabled($login)) {
            return '/index.php?module=TwoFactorAuth&action=loginTwoFactorAuth';
        }

        return $this->redirects->destination($request, $redirectParameter);
    }

    public function requiresTwoFactor(Request $request, string $login): bool
    {
        return $this->twoFactor->isEnabled($login)
            && ! $this->sessions->hasVerifiedTwoFactor($request->session());
    }
}
