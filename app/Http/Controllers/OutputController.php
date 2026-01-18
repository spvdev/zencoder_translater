<?php

namespace App\Http\Controllers;

use App\Models\Output;

class OutputController extends Controller
{
    /**
     * Get output details.
     * GET /v2/outputs/{id}
     */
    public function show($id)
    {
        $output = Output::with('job')->find($id);

        if (!$output) {
            return $this->notFoundResponse('Output');
        }

        return response()->json($output->toZencoderDetails());
    }

    /**
     * Get output progress.
     * GET /v2/outputs/{id}/progress
     */
    public function progress($id)
    {
        $output = Output::with('job')->find($id);

        if (!$output) {
            return $this->notFoundResponse('Output');
        }

        return response()->json([
            'state' => $output->state,
            'progress' => $output->progress,
            'current_event' => $output->state,
            'current_event_progress' => $output->progress,
        ]);
    }
}
