<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class FoundationTest extends TestCase
{
    public function test_health_endpoint_is_available(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_root_redirects_to_the_matomo_front_controller(): void
    {
        $this->get('/')
            ->assertRedirect('/index.php');
    }
}
