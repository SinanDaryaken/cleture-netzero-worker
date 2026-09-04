<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_root_returns_worker_health_response(): void
    {
        $this->getJson('/')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
            ]);
    }
}
