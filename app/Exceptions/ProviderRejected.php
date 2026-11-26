<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The provider answered with a clear error (4xx, or 503 overloaded): we know
 * what happened. Nothing ambiguous about whether a generation was created.
 */
class ProviderRejected extends RuntimeException
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct("Pixelforge rejected the request ({$httpStatus} {$errorCode}): {$message}");
    }
}
