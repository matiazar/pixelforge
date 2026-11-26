<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The stored markup is not a whole percent 0..100. Nothing is priced with it:
 * casting it would silently charge a wrong margin (e.g. (int) '' is 0%).
 */
class InvalidMarkupSetting extends RuntimeException
{
    public static function for(string $stored): self
    {
        return new self("Markup setting [{$stored}] is not a whole percent 0..100.");
    }
}
