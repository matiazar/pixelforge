<?php

namespace App\Http\Requests;

use App\Models\Generation;
use Illuminate\Foundation\Http\FormRequest;

class RetryGenerationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $generation = $this->route('generation');

        return $generation instanceof Generation
            && $this->user()?->can('retry', $generation) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
