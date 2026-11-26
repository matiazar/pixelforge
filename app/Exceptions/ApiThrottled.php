<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Our own limiter refused the call before sending it: nothing reached the provider.
 */
class ApiThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Pixelforge API budget used; retry in {$retryAfter}s");
    }
}
