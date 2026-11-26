<?php

namespace App\Http\Requests\Admin;

/**
 * Global settings. A markup change applies to generations created after it;
 * each generation keeps the markup it was estimated and held with.
 */
class UpdateAppSettingsRequest extends AdminRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'timezone' => ['required', 'string', 'timezone:all'],
            'daily_limit' => ['required', 'integer', 'min:1', 'max:1000'],
            'registration_open' => ['required', 'boolean'],
            'markup_percent' => ['required', 'integer', 'min:0', 'max:100'],
        ];
    }
}
