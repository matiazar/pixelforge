<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Missing, malformed, stale or wrong Pixelforge-Signature. Answered with 400;
 * not reported to the admin (anyone can hit a public endpoint).
 */
class InvalidWebhookSignature extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['error' => 'invalid_signature'], 400);
    }

    public function report(): bool
    {
        // Logged (no body, no signature) so a wrong secret after a deploy is visible.
        Log::warning('Rejected Pixelforge webhook signature', ['reason' => $this->getMessage()]);

        return true; // handled: no admin mail for noise from the internet
    }
}
