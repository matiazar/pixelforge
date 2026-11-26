<?php

namespace App\Http\Requests;

use App\Services\PricingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    public const MAX_PROMPT = 4000;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT],
            'size' => ['required', Rule::in(PricingService::SIZES)],
            'quality' => ['required', Rule::in(PricingService::QUALITIES)],
            'reference_images' => ['sometimes', 'array', 'max:'.PricingService::MAX_REFERENCES],
            'reference_images.*' => ['required', 'string', 'max:2048', 'url:http,https'],
            'form_token' => ['required', 'uuid'],
        ];
    }
}
