<?php

declare(strict_types=1);

namespace App\Http\Controllers\Matomo;

use App\Http\Controllers\Controller;
use App\Matomo\Login\UiAuthenticationGate;
use App\Matomo\Login\UiSessionFingerprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class CoreHomeController extends Controller
{
    public function __construct(
        private readonly UiAuthenticationGate $gate,
        private readonly UiSessionFingerprint $sessions,
    ) {}

    public function __invoke(Request $request): View|RedirectResponse
    {
        $home = $this->gate->home($request);
        if ($home !== '/index.php?module=CoreHome&action=index') {
            return redirect($home);
        }

        return view('core-home', ['login' => (string) $this->sessions->login($request->session())]);
    }
}
