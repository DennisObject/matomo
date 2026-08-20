<?php

declare(strict_types=1);

namespace Tests\Unit\Matomo;

use App\Matomo\Users\UserPresenter;
use PHPUnit\Framework\TestCase;

final class UserPresenterTest extends TestCase
{
    public function test_non_superuser_only_sees_own_email_and_safe_fields(): void
    {
        $presenter = new UserPresenter;

        $visible = $presenter->present([
            'login' => 'other',
            'email' => 'private@example.test',
            'password' => 'hash',
            'invite_token' => 'secret',
            'superuser_access' => 0,
            'invited_by' => 'admin',
        ], 'admin', false, true);

        $this->assertSame([
            'login' => 'other',
            'invite_status' => 'active',
            'superuser_access' => 0,
            'invited_by' => 'admin',
        ], $visible);
    }

    public function test_superuser_gets_2fa_state_but_not_secret(): void
    {
        $presenter = new UserPresenter;

        $visible = $presenter->present([
            'login' => 'alice',
            'email' => 'alice@example.test',
            'twofactor_secret' => 'secret',
        ], 'root', true, true);

        $this->assertTrue($visible['uses_2fa']);
        $this->assertArrayNotHasKey('twofactor_secret', $visible);
    }
}
