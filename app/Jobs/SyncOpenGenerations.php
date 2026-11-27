<?php

namespace App\Jobs;

use App\Services\PollingService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled every 10 seconds: polling backup for webhooks.
 * $force (admin "Sync pending") also asks about generations synced moments ago.
 */
class SyncOpenGenerations implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** A lost queued job (jobs table wiped, worker killed) frees its unique lock after this, instead of never. */
    public int $uniqueFor = 60;

    public function __construct(public bool $force = false) {}

    /**
     * A queued scheduled run must not swallow the admin's forced one.
     */
    public function uniqueId(): string
    {
        return $this->force ? 'force' : 'scheduled';
    }

    public function handle(PollingService $polling): void
    {
        $polling->syncOpen($this->force);
    }
}
