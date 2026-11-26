<?php

namespace App\Http\Requests\Admin;

/**
 * Global settings. The 25% markup is fixed in config and not editable here.
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
        ];
    }
}
