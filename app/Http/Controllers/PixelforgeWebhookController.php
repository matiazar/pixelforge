<?php

namespace App\Http\Controllers;

use App\Services\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public endpoint for Pixelforge. Authenticated by HMAC signature (no session, no CSRF).
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookService $webhooks): Response
    {
        // Raw body: the signature is computed over the exact bytes received.
        $webhooks->receive(
            $request->getContent(),
            $request->header('Pixelforge-Signature'),
            $request->header('Pixelforge-Delivery-Attempt'),
        );

        return response()->noContent();
    }
}
