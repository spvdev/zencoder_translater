<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Lumen\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that should not be reported.
     *
     * @var array
     */
    protected $dontReport = [
        AuthorizationException::class,
        HttpException::class,
        ModelNotFoundException::class,
        ValidationException::class,
    ];

    /**
     * Report or log an exception.
     */
    public function report(Throwable $exception): void
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $exception): JsonResponse
    {
        // Validation errors
        if ($exception instanceof ValidationException) {
            $errors = collect($exception->errors())->flatten()->toArray();
            return response()->json(['errors' => $errors], 422);
        }

        // Model not found
        if ($exception instanceof ModelNotFoundException) {
            return response()->json(['errors' => ['Resource not found']], 404);
        }

        // HTTP exceptions
        if ($exception instanceof HttpException) {
            return response()->json(
                ['errors' => [$exception->getMessage() ?: 'HTTP Error']],
                $exception->getStatusCode()
            );
        }

        // Generic error (production vs development)
        if (config('app.debug')) {
            return response()->json([
                'errors' => [$exception->getMessage()],
                'exception' => get_class($exception),
                'trace' => $exception->getTraceAsString(),
            ], 500);
        }

        return response()->json(['errors' => ['Internal server error']], 500);
    }
}
