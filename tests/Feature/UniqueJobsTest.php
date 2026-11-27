<?php

use App\Jobs\DispatchPendingGenerations;
use App\Jobs\SyncOpenGenerations;
use Illuminate\Support\Facades\Queue;

// A queued job that is lost (jobs table wiped, worker killed) must not block the scheduler forever.
it('lets the scheduler queue the job again once a stale unique lock expires', function (object $job) {
    Queue::fake();

    dispatch($job);
    dispatch(clone $job);
    Queue::assertPushed($job::class, 1);

    $this->travel(61)->seconds();

    dispatch(clone $job);
    Queue::assertPushed($job::class, 2);
})->with([
    'dispatcher' => fn () => new DispatchPendingGenerations,
    'polling' => fn () => new SyncOpenGenerations,
]);
