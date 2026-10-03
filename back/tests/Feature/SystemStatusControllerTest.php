<?php

namespace Tests\Feature;

use Tests\TestCase;

class SystemStatusControllerTest extends TestCase
{
    public function test_returns_operational_status_from_versioned_api(): void
    {
        $response = $this->getJson('/api/v1/system/status');

        $response
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'api_version' => 'v1',
                    'name' => 'UCG Platform API',
                    'status' => 'operational',
                ],
            ]);
    }

    public function test_allows_configured_frontend_origin(): void
    {
        $response = $this
            ->withHeader('Origin', 'http://localhost:5173')
            ->getJson('/api/v1/system/status');

        $response
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }
}
