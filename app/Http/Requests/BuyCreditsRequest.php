<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Fictitious top-up: only one of the configured packs, never a free amount.
 */
class BuyCreditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var list<string> $packs */
        $packs = config('pixelforge.credit_packs');

        return [
            'pack' => ['required', 'string', Rule::in($packs)],
            'form_token' => ['required', 'uuid'],
        ];
    }
}
