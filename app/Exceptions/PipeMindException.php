<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Domain errors the frontend switches on.
 *
 * A failed AI analysis is NOT a failed pipeline, and a dead integration is NOT a
 * server error. The UI has to distinguish these to be honest about what broke,
 * which is why they carry codes rather than being generic 500s.
 */
abstract class PipeMindException extends Exception
{
    protected string $errorCode = 'PIPEMIND_ERROR';

    protected int $status = 500;

    protected bool $retryable = false;

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function render(Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
            'retryable' => $this->retryable,
        ], $this->status);
    }
}
