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
use Illuminate\Support\Str;

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
        $payload = [
            'prompt' => $data['prompt'],
            'size' => $data['size'],
            'quality' => $data['quality'],
            'reference_images' => array_values($data['reference_images'] ?? []),
        ];

        // Double submit: same form token, same user -> the generation already created.
        return $this->open($user, $payload, $data['form_token'], null);
    }

    /**
     * New generation with the same request as a failed one: new id, key and hold.
     * retry_of_id is unique, so a double click returns the first retry.
     */
    public function retry(User $user, Generation $failed): Generation
    {
        /** @var array{prompt: string, size: string, quality: string, reference_images: array<int, string>} $payload */
        $payload = $failed->request_payload;

        return $this->open($user, $payload, (string) Str::uuid(), $failed);
    }

    /**
     * @param  array{prompt: string, size: string, quality: string, reference_images: array<int, string>}  $payload
     */
    private function open(User $user, array $payload, string $formToken, ?Generation $retryOf): Generation
    {
        // HTTP (pricing table) stays outside the transaction.
        $table = $this->pricing->currentTable();
        // Read once: the hold and the final charge must use the same markup.
        $markup = $this->pricing->markupPercent();
        $estimate = $this->pricing->estimate($payload['prompt'], $payload['size'], $payload['quality'], count($payload['reference_images']), $table, $markup);

        return DB::transaction(function () use ($user, $payload, $formToken, $retryOf, $estimate, $markup) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $existing = $retryOf !== null
                ? Generation::where('retry_of_id', $retryOf->id)->first()
                : Generation::where('form_token', $formToken)->where('user_id', $user->id)->first();

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
                'form_token' => $formToken,
                'retry_of_id' => $retryOf?->id,
                'request_payload' => $payload,
                'status' => GenerationStatus::Pending,
                'estimate_min_nano' => $estimate->priceMin,
                'estimate_max_nano' => $estimate->priceMax,
                'markup_percent' => $markup,
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
