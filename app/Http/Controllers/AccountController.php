<?php

namespace App\Http\Controllers;

class AccountController extends Controller
{
    /**
     * Get account information.
     * GET /v2/account
     */
    public function show()
    {
        // Return mock response for compatibility
        return response()->json([
            'account_state' => 'active',
            'plan' => 'enterprise',
            'minutes_used' => 0,
            'minutes_included' => -1, // Unlimited
            'billing_state' => 'active',
            'integration_mode' => false,
        ]);
    }

    /**
     * Get usage minutes report.
     * GET /v2/reports/minutes
     */
    public function minutesReport()
    {
        // Return mock response since AWS billing is separate
        return response()->json([
            'total' => [
                'live' => 0,
                'vod' => 0,
                'total' => 0,
            ],
            'statistics' => [
                'by_month' => [],
            ],
        ]);
    }
}
