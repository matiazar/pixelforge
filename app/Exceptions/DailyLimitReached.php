<?php

namespace App\Exceptions;

use Illuminate\Http\RedirectResponse;
use RuntimeException;

class DailyLimitReached extends RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("You reached today's limit of {$limit} generations. It resets at 00:00 UTC.");
    }

    public function render(): RedirectResponse
    {
        return back()->withInput()->withErrors(['daily_limit' => $this->getMessage()]);
    }
}
