<?php

namespace App\Http\Requests\Admin;

use App\Models\Setting;

/**
 * Report range: calendar days in the timezone from the global settings.
 */
class ReportRequest extends DateRangeRequest
{
    public function timezone(): string
    {
        return Setting::get('timezone');
    }

    protected function defaultDays(): int
    {
        return 30;
    }

    protected function maxDays(): int
    {
        return 366;
    }
}
