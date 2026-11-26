<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Exceptions\ApiThrottled;
use App\Exceptions\ProviderRejected;
use App\Exceptions\ProviderUnavailable;
use App\Exceptions\UnsupportedRate;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Backup for lost or late webhooks: asks the provider about accepted
 * generations that have had no news for 15 seconds or more.
 */
class PollingService
{
    public const STALE_AFTER_SECONDS = 15;

    public const MAX_PER_RUN = 20;

    public function __construct(
        private PixelforgeClient $client,
        private GenerationStateService $state,
    ) {}

    /**
     * Returns how many generations were looked up successfully.
     * $force (admin "Sync pending") also asks about the ones synced moments ago.
     */
    public function syncOpen(bool $force = false): int
    {
        $open = Generation::whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->when(! $force, fn ($q) => $q->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', now()->subSeconds(self::STALE_AFTER_SECONDS))))
            ->orderByRaw('last_synced_at asc nulls first')
            ->limit(self::MAX_PER_RUN)
            ->get();

        $synced = 0;

        foreach ($open as $generation) {
            try {
                $this->state->apply($generation, $this->client->getGeneration((string) $generation->provider_id));
                $synced++;
            } catch (ApiThrottled) {
                break; // budget used: the next scheduled run continues
            } catch (ProviderRejected $e) {
                if ($e->httpStatus === 404) {
                    // Unknown to the provider (e.g. another API key): it will never finish there.
                    $this->state->failLocally($generation, 'not_found_at_provider', 'The provider no longer knows this generation.');

                    continue;
                }

                $this->skip($generation, $e);
            } catch (ProviderUnavailable $e) {
                $this->skip($generation, $e);
            } catch (InvalidArgumentException|UnsupportedRate $e) {
                report($e); // unusable payload: admin should look, the rest keeps going
                $this->skip($generation, $e);
            }
        }

        return $synced;
    }

    /**
     * Not decided this run; touched so one bad row does not hog every run.
     */
    private function skip(Generation $generation, Throwable $e): void
    {
        Log::warning('Polling lookup failed', ['generation' => $generation->id, 'reason' => $e->getMessage()]);
        $generation->forceFill(['last_synced_at' => now()])->save();
    }
}
