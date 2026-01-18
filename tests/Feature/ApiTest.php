<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiTest extends TestCase
{
    /**
     * Test health endpoint.
     */
    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->get('/health');

        $response->seeStatusCode(200);
        $response->seeJson(['status' => 'healthy']);
    }

    /**
     * Test root endpoint.
     */
    public function test_root_endpoint_returns_service_info(): void
    {
        $response = $this->get('/');

        $response->seeStatusCode(200);
        $response->seeJsonStructure(['service', 'version', 'status']);
    }

    /**
     * Test account endpoint.
     */
    public function test_account_endpoint_returns_account_info(): void
    {
        $response = $this->get('/v2/account');

        $response->seeStatusCode(200);
        $response->seeJsonStructure([
            'account_state',
            'plan',
            'minutes_used',
            'billing_state',
        ]);
    }

    /**
     * Test job creation validation.
     */
    public function test_job_creation_requires_input(): void
    {
        $response = $this->post('/v2/jobs', []);

        $response->seeStatusCode(422);
        $response->seeJsonStructure(['errors']);
    }
}
