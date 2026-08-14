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

    public function test_foundation_does_not_replace_matomo(): void
    {
        $this->get('/')
            ->assertServiceUnavailable()
            ->assertExactJson([
                'name' => 'Matomo Laravel runtime',
                'status' => 'foundation',
            ]);
    }
}
