<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Login\TrustedLoginRedirect;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class TrustedLoginRedirectTest extends TestCase
{
    public function test_accepts_a_same_host_non_login_redirect(): void
    {
        $redirects = new TrustedLoginRedirect;
        $request = Request::create('http://localhost/index.php', 'POST', [
            'form_redirect' => 'http://localhost/index.php?module=SitesManager&action=index',
        ]);

        $this->assertSame(
            '/index.php?module=SitesManager&action=index',
            $redirects->destination($request),
        );
    }

    public function test_rejects_login_module_and_foreign_hosts(): void
    {
        $redirects = new TrustedLoginRedirect(['localhost']);
        $home = '/index.php?module=CoreHome&action=index';
        $login = Request::create('http://localhost/index.php', 'POST', [
            'form_redirect' => 'http://localhost/index.php?module=Login',
        ]);
        $foreign = Request::create('http://localhost/index.php', 'POST', [
            'form_redirect' => 'http://evil.test/index.php?module=SitesManager',
        ]);

        $this->assertSame($home, $redirects->destination($login));
        $this->assertSame($home, $redirects->destination($foreign));
    }

    public function test_rejects_hosts_outside_the_trusted_list(): void
    {
        $redirects = new TrustedLoginRedirect(['analytics.example'], true);
        $request = Request::create('http://localhost/index.php', 'POST', [
            'form_redirect' => 'http://localhost/index.php?module=SitesManager',
        ]);

        $this->assertSame(
            '/index.php?module=CoreHome&action=index',
            $redirects->destination($request),
        );
    }
}
