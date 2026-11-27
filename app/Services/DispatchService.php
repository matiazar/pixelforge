<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Exceptions\ApiThrottled;
use App\Exceptions\ProviderRejected;
use App\Exceptions\ProviderUnavailable;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends pending generations to Pixelforge, FIFO by next_attempt_at, while
 * fewer than 3 accepted generations lack a final state.
 *
 * Before each POST the generation is marked ambiguous and committed, so a
 * crash mid-call leaves the mark. It goes back to false only with a clear
 * rejection and no earlier ambiguous attempt (or when the provider shows it).
 */
class DispatchService
{
    public const MAX_FAILED_ATTEMPTS = 6;

    public const GIVE_UP_AFTER_MINUTES = 15;

    /** Keeps one run well inside the lock TTL (each POST may take the 10 s timeout). */
    public const MAX_ATTEMPTS_PER_RUN = 5;

    private const LOCK_KEY = 'pixelforge:dispatch';

    private const PAUSE_KEY = 'pixelforge:dispatch_paused_until';

    private const INVALID_KEY_PAUSE_SECONDS = 600;

    private const RECONCILE_RETRY_SECONDS = 30;

    private const CLOCK_SKEW_SECONDS = 60;

    public function __construct(
        private PixelforgeClient $client,
        private GenerationStateService $state,
    ) {}

    /**
     * Returns how many generations were attempted.
     */
    public function dispatchPending(): int
    {
        $lock = Cache::lock(self::LOCK_KEY, 60);

        if (! $lock->get()) {
            return 0; // another dispatcher is running
        }

        try {
            return $this->run();
        } finally {
            $lock->release();
        }
    }

    /**
     * Looks the generation up at the provider by metadata.local_id, paging
     * (newest first) back to its creation time. Null when it is not there.
     * Provider errors propagate: the caller must not decide on them.
     *
     * @return array<string, mixed>|null
     */
    public function findAtProvider(Generation $generation): ?array
    {
        $oldest = $generation->created_at?->copy()->subSeconds(self::CLOCK_SKEW_SECONDS);
        $cursor = null;

        do {
            $page = $this->client->listGenerations($cursor, 100);

            foreach ($page['data'] as $remote) {
                if (($remote['metadata']['local_id'] ?? null) === $generation->id) {
                    return $remote;
                }
            }

            $last = end($page['data']);
            $pastCreation = $last !== false && $oldest !== null
                && isset($last['created_at']) && Carbon::parse($last['created_at'])->lt($oldest);

            $cursor = $page['next_cursor'];
        } while ($page['has_more'] && $cursor !== null && ! $pastCreation);

        return null;
    }

    private function run(): int
    {
        $attempted = 0;

        while ($attempted < self::MAX_ATTEMPTS_PER_RUN && ! $this->isPaused() && $this->activeCount() < GenerationService::MAX_ACTIVE) {
            $next = Generation::where('status', GenerationStatus::Pending)
                ->where('next_attempt_at', '<=', now())
                ->orderBy('next_attempt_at')
                ->orderBy('id')
                ->first();

            if ($next === null) {
                break;
            }

            $attempted++;

            if (! $this->attempt($next)) {
                break; // provider or our budget says stop for now
            }
        }

        return $attempted;
    }

    /**
     * Returns false when the dispatcher should stop this run.
     */
    private function attempt(Generation $generation): bool
    {
        if ($this->shouldGiveUp($generation)) {
            $this->giveUp($generation);

            return true;
        }

        $wasAmbiguous = $generation->ambiguous;

        // Committed before the call: if the worker dies mid-request, the mark stays.
        $generation->forceFill([
            'ambiguous' => true,
            'attempt_started_at' => now(),
            'api_key_fingerprint' => $this->client->apiKeyFingerprint(),
        ])->save();

        try {
            $remote = $this->client->createGeneration($generation->id, $this->body($generation));
        } catch (ApiThrottled $e) {
            $this->requeue($generation, $wasAmbiguous, $e->retryAfter);

            return false;
        } catch (ProviderUnavailable $e) {
            Log::warning('Ambiguous dispatch attempt', ['generation' => $generation->id, 'reason' => $e->getMessage()]);
            $this->countFailedAttempt($generation, ambiguous: true, retryAfter: null);

            return true;
        } catch (ProviderRejected $e) {
            return $this->handleRejection($generation, $e, $wasAmbiguous);
        }

        $this->state->apply($generation, $remote);

        return true;
    }

    private function handleRejection(Generation $generation, ProviderRejected $e, bool $wasAmbiguous): bool
    {
        switch ($e->errorCode) {
            case 'concurrency_limit_exceeded':
                $this->requeue($generation, $wasAmbiguous, $e->retryAfter ?? 5);

                return false;

            case 'rate_limit_exceeded':
                $this->requeue($generation, $wasAmbiguous, $e->retryAfter ?? 60);

                return false;

            case 'daily_limit_exceeded':
                $until = now('UTC')->addDay()->startOfDay();
                // Not an error to report, but the queue stops for hours: leave a trace.
                Log::warning('Dispatch paused: Pixelforge daily quota reached', ['until' => $until->toIso8601String()]);
                $this->pauseUntil($until);
                $this->requeue($generation, $wasAmbiguous, 0);
                $this->failWaitingForQuota();

                return false;

            case 'invalid_api_key':
                report($e); // admin must fix the key; nothing is failed meanwhile
                $this->pauseUntil(now()->addSeconds(self::INVALID_KEY_PAUSE_SECONDS));
                $this->requeue($generation, $wasAmbiguous, 0);

                return false;

            case 'overloaded':
                $this->countFailedAttempt($generation, ambiguous: $wasAmbiguous, retryAfter: $e->retryAfter);

                return true;

            case 'idempotency_key_reused':
                report($e);
                $this->reconcileOrWait($generation);

                return true;

            default:
                // 400/422 and other clear rejections: definitive unless an earlier attempt was ambiguous.
                $wasAmbiguous
                    ? $this->reconcileOrWait($generation)
                    : $this->state->failLocally($generation, $e->errorCode, $this->userMessage($e->errorCode));

                return true;
        }
    }

    /**
     * The provider quota is gone until the next UTC day: waiting hours and then
     * failing helps nobody. Pending generations never sent ambiguously fail now
     * (hold released); ambiguous ones stay until they can be reconciled.
     */
    private function failWaitingForQuota(): void
    {
        Generation::where('status', GenerationStatus::Pending)
            ->where('ambiguous', false)
            ->each(fn (Generation $waiting) => $this->state->failLocally(
                $waiting,
                'provider_daily_quota',
                $this->userMessage('provider_daily_quota'),
            ));
    }

    private function shouldGiveUp(Generation $generation): bool
    {
        return $generation->attempts >= self::MAX_FAILED_ATTEMPTS
            || ($generation->created_at !== null && $generation->created_at->lte(now()->subMinutes(self::GIVE_UP_AFTER_MINUTES)));
    }

    private function giveUp(Generation $generation): void
    {
        if ($generation->ambiguous) {
            $this->reconcileOrWait($generation);

            return;
        }

        $this->state->failLocally($generation, 'dispatch_gave_up', $this->userMessage('dispatch_gave_up'));
    }

    /**
     * Adopts the provider's generation if it exists; fails locally if it does
     * not; leaves it pending if the lookup itself fails.
     */
    private function reconcileOrWait(Generation $generation): void
    {
        try {
            $remote = $this->findAtProvider($generation);
        } catch (ProviderUnavailable|ProviderRejected|ApiThrottled $e) {
            Log::warning('Reconciliation lookup failed, will retry', ['generation' => $generation->id, 'reason' => $e->getMessage()]);
            $generation->forceFill(['next_attempt_at' => now()->addSeconds(self::RECONCILE_RETRY_SECONDS)])->save();

            return;
        }

        if ($remote !== null) {
            $this->state->apply($generation, $remote);

            return;
        }

        $this->state->failLocally($generation, 'not_accepted', $this->userMessage('not_accepted'));
    }

    /**
     * Not our fault and not counted: back to the queue after $seconds.
     */
    private function requeue(Generation $generation, bool $wasAmbiguous, int $seconds): void
    {
        $generation->forceFill([
            'ambiguous' => $wasAmbiguous,
            'next_attempt_at' => now()->addSeconds($seconds),
        ])->save();
    }

    private function countFailedAttempt(Generation $generation, bool $ambiguous, ?int $retryAfter): void
    {
        $attempts = $generation->attempts + 1;
        $backoff = $retryAfter ?? min(5 * (2 ** ($attempts - 1)), 120);

        $generation->forceFill([
            'attempts' => $attempts,
            'ambiguous' => $ambiguous,
            'next_attempt_at' => now()->addSeconds($backoff),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Generation $generation): array
    {
        return $generation->request_payload + ['metadata' => ['local_id' => $generation->id]];
    }

    private function activeCount(): int
    {
        return Generation::whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])->count();
    }

    private function isPaused(): bool
    {
        return (int) Cache::get(self::PAUSE_KEY, 0) > now()->getTimestamp();
    }

    private function pauseUntil(CarbonInterface $until): void
    {
        Cache::put(self::PAUSE_KEY, $until->getTimestamp(), $until);
    }

    private function userMessage(string $code): string
    {
        return match ($code) {
            'content_policy_violation' => 'The prompt was rejected by the content policy.',
            'validation_failed' => 'The request was rejected as invalid.',
            'provider_daily_quota' => 'The image service reached its daily limit. Please try again after 00:00 UTC. You were not charged.',
            default => 'The image service did not accept the request. You were not charged.',
        };
    }
}
