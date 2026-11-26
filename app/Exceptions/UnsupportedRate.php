<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A provider rate that cannot be expressed as a whole number of nano-USD per token.
 */
class UnsupportedRate extends RuntimeException
{
    public static function for(string $type, string $rate): self
    {
        return new self("Rate for [{$type}] ({$rate} USD per 1M tokens) is not a whole number of nano-USD per token.");
    }
}
