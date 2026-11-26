<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the owner sees: their price, never the provider cost. Money goes out as
 * formatted strings so the browser never does float math.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = $this->request_payload;
        $imageExpired = $this->image_url !== null
            && $this->image_url_expires_at !== null
            && $this->image_url_expires_at->isPast();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'prompt' => $payload['prompt'] ?? '',
            'size' => $payload['size'] ?? null,
            'quality' => $payload['quality'] ?? null,
            'reference_count' => count($payload['reference_images'] ?? []),
            'estimate' => [
                'min' => Money::format($this->estimate_min_nano),
                'max' => Money::format($this->estimate_max_nano),
            ],
            'price' => $this->price_nano !== null && $this->status->isFinal() ? Money::format($this->price_nano) : null,
            'error_message' => $this->status === GenerationStatus::Failed ? $this->userErrorMessage() : null,
            'image_url' => $imageExpired ? null : $this->image_url,
            'image_expired' => $imageExpired,
            'is_retry' => $this->retry_of_id !== null,
            'can_retry' => $this->status === GenerationStatus::Failed && ! ($this->retried_by_exists ?? false),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }

    private function userErrorMessage(): string
    {
        return $this->error_message ?? 'The generation failed. You were not charged.';
    }
}
