<?php

namespace App\Services;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only writer of money. Every entry and its balance update happen in one
 * transaction with the user row locked; the unique (generation_id, type)
 * index makes a second hold/release/charge for a generation impossible.
 */
class LedgerService
{
    /**
     * With an idempotency key, the same key for the same user returns the first
     * top-up instead of adding money again.
     */
    public function topUp(User $user, int $amountNano, ?string $idempotencyKey = null): LedgerEntry
    {
        if ($amountNano <= 0) {
            throw new InvalidArgumentException('Top-up amount must be positive');
        }

        return DB::transaction(function () use ($user, $amountNano, $idempotencyKey) {
            $this->lockUser($user->id);

            if ($idempotencyKey !== null) {
                $existing = LedgerEntry::where('user_id', $user->id)->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            return $this->record($user->id, null, LedgerEntry::TOPUP, $amountNano, $idempotencyKey);
        });
    }

    /**
     * Reserves the maximum estimated price. The caller has already checked the
     * balance while holding the user lock.
     */
    public function hold(Generation $generation): LedgerEntry
    {
        return DB::transaction(function () use ($generation) {
            $this->lockUser($generation->user_id);

            return $this->record($generation->user_id, $generation->id, LedgerEntry::HOLD, -$generation->estimate_max_nano);
        });
    }

    /**
     * Releases the hold and, when $priceNano is given (succeeded), charges it.
     * Safe to call again: existing entries are not repeated, so a local failure
     * later overturned by a provider success is charged exactly once.
     */
    public function settle(Generation $generation, ?int $priceNano): void
    {
        DB::transaction(function () use ($generation, $priceNano) {
            $this->lockUser($generation->user_id);

            $existing = LedgerEntry::where('generation_id', $generation->id)->pluck('amount_nano', 'type');
            $hold = isset($existing[LedgerEntry::HOLD]) ? (int) $existing[LedgerEntry::HOLD] : null;

            if ($hold !== null && ! $existing->has(LedgerEntry::RELEASE)) {
                $this->record($generation->user_id, $generation->id, LedgerEntry::RELEASE, -$hold);
            }

            if ($priceNano !== null && ! $existing->has(LedgerEntry::CHARGE)) {
                $this->record($generation->user_id, $generation->id, LedgerEntry::CHARGE, -$priceNano);
            }
        });
    }

    private function lockUser(int $userId): void
    {
        User::whereKey($userId)->lockForUpdate()->firstOrFail();
    }

    private function record(int $userId, ?string $generationId, string $type, int $amountNano, ?string $idempotencyKey = null): LedgerEntry
    {
        $entry = LedgerEntry::create([
            'user_id' => $userId,
            'generation_id' => $generationId,
            'type' => $type,
            'amount_nano' => $amountNano,
            'idempotency_key' => $idempotencyKey,
        ]);

        User::whereKey($userId)->increment('balance_nano', $amountNano);

        return $entry;
    }
}
