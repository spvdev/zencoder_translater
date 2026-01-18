<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;

class Controller extends BaseController
{
    /**
     * Return a JSON error response.
     */
    protected function errorResponse(array $errors, int $status = 400)
    {
        return response()->json(['errors' => $errors], $status);
    }

    /**
     * Return a not found error response.
     */
    protected function notFoundResponse(string $resource = 'Resource')
    {
        return $this->errorResponse(["{$resource} not found"], 404);
    }

    /**
     * Return a conflict error response.
     */
    protected function conflictResponse(string $message)
    {
        return $this->errorResponse([$message], 409);
    }
}
