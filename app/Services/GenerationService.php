<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Exceptions\DailyLimitReached;
use App\Exceptions\InsufficientBalance;
use App\Jobs\DispatchPendingGenerations;
use App\Models\Generation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GenerationService
{
    /** Provider limit: accepted generations without a final state, shared by all users. */
    public const MAX_ACTIVE = 3;

    public function __construct(
        private PricingService $pricing,
        private LedgerService $ledger,
    ) {}

    /**
     * Creates a pending generation and holds its maximum price. Nothing is sent
     * to the provider here; the dispatcher does that.
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images?: array<int, string>, form_token: string}  $data
     */
    public function create(User $user, array $data): Generation
    {
        $references = array_values($data['reference_images'] ?? []);

        // HTTP (pricing table) stays outside the transaction.
        $table = $this->pricing->currentTable();
        $estimate = $this->pricing->estimate($data['prompt'], $data['size'], $data['quality'], count($references), $table);

        return DB::transaction(function () use ($user, $data, $references, $estimate) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            // Double submit: same form token, same user -> the generation already created.
            $existing = Generation::where('form_token', $data['form_token'])->where('user_id', $user->id)->first();

            if ($existing !== null) {
                return $existing;
            }

            $limit = (int) Setting::get('daily_limit');
            $today = Generation::where('user_id', $user->id)
                ->where('created_at', '>=', now('UTC')->startOfDay())
                ->count();

            if ($today >= $limit) {
                throw new DailyLimitReached($limit);
            }

            if ($locked->balance_nano < $estimate->priceMax) {
                throw new InsufficientBalance($estimate->priceMax - $locked->balance_nano);
            }

            $generation = Generation::create([
                'user_id' => $user->id,
                'form_token' => $data['form_token'],
                'request_payload' => [
                    'prompt' => $data['prompt'],
                    'size' => $data['size'],
                    'quality' => $data['quality'],
                    'reference_images' => $references,
                ],
                'status' => GenerationStatus::Pending,
                'estimate_min_nano' => $estimate->priceMin,
                'estimate_max_nano' => $estimate->priceMax,
                'next_attempt_at' => now(),
            ]);

            $this->ledger->hold($generation);

            DispatchPendingGenerations::dispatch()->afterCommit();

            return $generation;
        });
    }

    public function hasFreeSlot(): bool
    {
        return $this->activeCount() < self::MAX_ACTIVE;
    }

    /**
     * True when the generation will not be sent right away: all provider slots
     * are taken or older pending requests are ahead of it.
     */
    public function waitsForSlot(Generation $generation): bool
    {
        $ahead = Generation::where('status', GenerationStatus::Pending)
            ->whereKeyNot($generation->id)
            ->where('next_attempt_at', '<=', $generation->next_attempt_at)
            ->count();

        return $this->activeCount() + $ahead >= self::MAX_ACTIVE;
    }

    private function activeCount(): int
    {
        return Generation::whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])->count();
    }
}
