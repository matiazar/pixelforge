<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Correctly signed but not a usable event (bad JSON, missing id/type).
 */
class InvalidWebhookPayload extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['error' => 'invalid_payload'], 400);
    }

    public function report(): bool
    {
        return true; // handled: answered 400, no admin mail
    }
}
