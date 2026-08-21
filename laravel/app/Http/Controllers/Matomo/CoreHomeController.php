<?php

declare(strict_types=1);

namespace App\Http\Controllers\Matomo;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class CoreHomeController extends Controller
{
    public function __invoke(Request $request): View|RedirectResponse
    {
        $login = $request->session()->get('matomo.login');
        if (! is_string($login) || $login === '') {
            return redirect('/index.php?module=Login');
        }

        return view('core-home', ['login' => $login]);
    }
}
