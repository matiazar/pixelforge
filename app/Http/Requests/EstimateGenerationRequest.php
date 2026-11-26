<?php

namespace App\Http\Requests;

use App\Services\PricingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Draft of the generation form; only what the price depends on.
 */
class EstimateGenerationRequest extends FormRequest
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
        return [
            'prompt' => ['present', 'nullable', 'string', 'max:'.StoreGenerationRequest::MAX_PROMPT],
            'size' => ['required', Rule::in(PricingService::SIZES)],
            'quality' => ['required', Rule::in(PricingService::QUALITIES)],
            'reference_count' => ['required', 'integer', 'min:0', 'max:'.PricingService::MAX_REFERENCES],
        ];
    }

    /**
     * An empty prompt is still a valid draft: it prices as zero text tokens.
     *
     * @return array{prompt: string, size: string, quality: string, reference_count: int}
     */
    public function draft(): array
    {
        /** @var array{prompt: ?string, size: string, quality: string, reference_count: int|string} $data */
        $data = $this->validated();

        return [
            'prompt' => (string) $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_count' => (int) $data['reference_count'],
        ];
    }
}
