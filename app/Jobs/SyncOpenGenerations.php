<?php

namespace App\Jobs;

use App\Services\PollingService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled every 10 seconds: polling backup for webhooks.
 */
class SyncOpenGenerations implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(PollingService $polling): void
    {
        $polling->syncOpen();
    }
}
