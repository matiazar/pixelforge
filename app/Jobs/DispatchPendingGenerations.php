<?php

namespace App\Jobs;

use App\Services\DispatchService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Triggered on create, when a generation finishes, and by the scheduler.
 * The service holds its own lock, so overlapping runs are harmless.
 */
class DispatchPendingGenerations implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(DispatchService $dispatcher): void
    {
        $dispatcher->dispatchPending();
    }
}
