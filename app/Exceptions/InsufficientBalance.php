<?php

namespace App\Exceptions;

use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class InsufficientBalance extends RuntimeException
{
    public function __construct(public readonly int $shortfallNano)
    {
        parent::__construct('Not enough credit. You need $'.Money::format($shortfallNano).' more.');
    }

    public function render(): RedirectResponse
    {
        return back()->withInput()->withErrors(['balance' => $this->getMessage()]);
    }
}
