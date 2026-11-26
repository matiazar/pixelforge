<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Jobs\DispatchPendingGenerations;
use App\Models\Generation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The only place that changes a generation's status. POST responses, webhooks,
 * polling and reconciliation all come through here, so duplicates and
 * out-of-order events follow the same rules:
 * - row lock; status only moves forward (pending < queued < processing < final)
 * - final states reported by the provider are immutable
 * - a local failure is provisional: a later provider final state wins
 * - settlement (release + charge) happens in the same transaction
 */
class GenerationStateService
{
    public function __construct(
        private PricingService $pricing,
        private LedgerService $ledger,
    ) {}

    /**
     * Applies a provider generation object. Returns true when it changed something.
     *
     * @param  array<string, mixed>  $remote
     */
    public function apply(Generation $generation, array $remote): bool
    {
        return DB::transaction(function () use ($generation, $remote) {
            $current = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();
            $this->assertBelongsTo($current, $remote);
            $incoming = GenerationStatus::tryFrom((string) ($remote['status'] ?? ''));

            if ($incoming === null || $incoming === GenerationStatus::Pending) {
                Log::warning('Ignoring provider generation with unknown status', ['generation' => $current->id, 'status' => $remote['status'] ?? null]);

                return false;
            }

            $changed = $this->fillProviderIdentity($current, $remote);
            $current->last_synced_at = now();

            if ($this->isProviderFinal($current)) {
                $current->save();

                return $changed;
            }

            $overturnsLocalFailure = $current->failure_source === 'local' && $incoming->isFinal();

            if ($incoming->rank() > $current->status->rank() || $overturnsLocalFailure) {
                if ($overturnsLocalFailure) {
                    Log::warning('Provider final state overturns a local failure', ['generation' => $current->id, 'status' => $incoming->value]);
                }

                match ($incoming) {
                    GenerationStatus::Succeeded => $this->succeed($current, $remote),
                    GenerationStatus::Failed => $this->failFromProvider($current, $remote),
                    default => $current->status = $incoming,
                };

                if ($incoming->isFinal()) {
                    $this->freeSlot();
                }

                $changed = true;
            }

            $current->save();

            return $changed;
        });
    }

    /**
     * Provisional failure decided by us (e.g. gave up dispatching). Releases the hold.
     */
    public function failLocally(Generation $generation, string $code, string $message): void
    {
        DB::transaction(function () use ($generation, $code, $message) {
            $current = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($current->status->isFinal()) {
                return;
            }

            $current->fill([
                'status' => GenerationStatus::Failed,
                'failure_source' => 'local',
                'error_code' => $code,
                'error_message' => $message,
                'price_nano' => 0,
                'completed_at' => now(),
            ])->save();

            $this->ledger->settle($current, null);
            $this->freeSlot();
        });
    }

    /**
     * A finished generation frees a provider slot: let the dispatcher send the next one.
     */
    private function freeSlot(): void
    {
        DispatchPendingGenerations::dispatch()->afterCommit();
    }

    /**
     * A payload for another generation must never touch this row (wrong charge).
     *
     * @param  array<string, mixed>  $remote
     */
    private function assertBelongsTo(Generation $generation, array $remote): void
    {
        $localId = $remote['metadata']['local_id'] ?? null;

        if ($localId !== null && $localId !== $generation->id) {
            throw new InvalidArgumentException("Provider payload is for local generation [{$localId}], not [{$generation->id}]");
        }

        if ($generation->provider_id !== null && ($remote['id'] ?? null) !== $generation->provider_id) {
            throw new InvalidArgumentException("Provider payload id does not match generation [{$generation->id}]");
        }
    }

    private function isProviderFinal(Generation $generation): bool
    {
        return $generation->status->isFinal() && $generation->failure_source !== 'local';
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function fillProviderIdentity(Generation $generation, array $remote): bool
    {
        $changed = false;

        if ($generation->provider_id === null && is_string($remote['id'] ?? null)) {
            $generation->provider_id = $remote['id'];
            $changed = true;
        }

        // Rates are fixed by the provider at creation; the first ones we see are kept.
        if ($generation->rates === null && is_array($remote['rates'] ?? null)) {
            $generation->rates = $remote['rates'];
            $changed = true;
        }

        if ($generation->provider_created_at === null && is_string($remote['created_at'] ?? null)) {
            $generation->provider_created_at = Carbon::parse($remote['created_at']);
        }

        // The provider knows this generation: the attempt is no longer ambiguous.
        $generation->ambiguous = false;

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function succeed(Generation $generation, array $remote): void
    {
        /** @var array<string, int> $usage */
        $usage = (array) ($remote['usage'] ?? []);
        $cost = $this->pricing->costFromUsage($usage, (array) $generation->rates);
        $price = $this->pricing->priceFor($cost, $generation->markup_percent);

        $generation->fill([
            'status' => GenerationStatus::Succeeded,
            'failure_source' => null,
            'error_code' => null,
            'error_message' => null,
            'usage' => $usage,
            'cost_nano' => $cost,
            'price_nano' => $price,
            'image_url' => $remote['image_url'] ?? null,
            'image_url_expires_at' => isset($remote['image_url_expires_at']) ? Carbon::parse($remote['image_url_expires_at']) : null,
            'completed_at' => isset($remote['completed_at']) ? Carbon::parse($remote['completed_at']) : now(),
        ]);

        if ($price > $generation->estimate_max_nano) {
            Log::warning('Charged above the hold', ['generation' => $generation->id, 'price' => $price, 'hold' => $generation->estimate_max_nano]);
        }

        $this->ledger->settle($generation, $price);
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function failFromProvider(Generation $generation, array $remote): void
    {
        $generation->fill([
            'status' => GenerationStatus::Failed,
            'failure_source' => 'provider',
            'error_code' => $remote['error']['code'] ?? 'generation_failed',
            'error_message' => $remote['error']['message'] ?? null,
            'price_nano' => 0,
            'completed_at' => isset($remote['completed_at']) ? Carbon::parse($remote['completed_at']) : now(),
        ]);

        $this->ledger->settle($generation, null);
    }
}
