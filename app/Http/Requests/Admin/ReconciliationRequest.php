<?php

namespace App\Http\Requests\Admin;

/**
 * Reconciliation range: always UTC days (GET /v1/usage is UTC, max 92 days).
 */
class ReconciliationRequest extends DateRangeRequest
{
    protected function timezone(): string
    {
        return 'UTC';
    }

    protected function defaultDays(): int
    {
        return 7;
    }

    protected function maxDays(): int
    {
        return 92;
    }
}
