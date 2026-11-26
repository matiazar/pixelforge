<?php

namespace App\Http\Resources;

use App\Models\LedgerEntry;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One balance movement as the owner sees it. Signed amount, formatted on the server.
 *
 * @mixin LedgerEntry
 */
class LedgerEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'amount' => Money::format($this->amount_nano),
            'prompt' => $this->generation?->request_payload['prompt'] ?? null,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
