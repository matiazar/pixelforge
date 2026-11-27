<?php

use App\Enums\GenerationStatus;
use App\Exceptions\ProviderRejected;
use App\Jobs\DispatchPendingGenerations;
use App\Models\Generation;
use App\Models\User;
use App\Services\DispatchService;
use App\Services\GenerationService;
use App\Services\GenerationStateService;
use App\Services\LedgerService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    config(['services.pixelforge.base_url' => 'https://pf.test', 'services.pixelforge.api_key' => 'pf_key']);
    $this->dispatcher = app(DispatchService::class);
    $this->user = User::factory()->create();
    app(LedgerService::class)->topUp($this->user, 5_000_000_000);
});

function pendingGeneration(User $user, array $attributes = []): Generation
{
    $generation = Generation::factory()->for($user)->create($attributes + ['next_attempt_at' => now()->subSecond()]);
    app(LedgerService::class)->hold($generation);

    return $generation;
}

function accepted(string $localId, string $providerId = 'gen_1', string $status = 'queued'): array
{
    return [
        'id' => $providerId,
        'object' => 'generation',
        'status' => $status,
        'metadata' => ['local_id' => $localId],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'usage' => null,
        'error' => null,
        'created_at' => now()->toIso8601ZuluString(),
    ];
}

function rejected(string $code, int $status, array $headers = []): PromiseInterface
{
    return Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => 'msg']], $status, $headers);
}

it('sends the pending generation with its id as idempotency key and local_id', function () {
    $generation = pendingGeneration($this->user);
    Http::fake(['pf.test/v1/generations' => Http::response(accepted($generation->id), 202)]);

    $this->dispatcher->dispatchPending();

    $fresh = $generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Queued)
        ->and($fresh->provider_id)->toBe('gen_1')
        ->and($fresh->ambiguous)->toBeFalse()
        ->and($fresh->api_key_fingerprint)->toHaveLength(16);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', $generation->id)
        && $r['metadata'] === ['local_id' => $generation->id]
        && $r['prompt'] === $generation->request_payload['prompt']);
});

it('marks the attempt as ambiguous before calling the provider', function () {
    $generation = pendingGeneration($this->user);
    Http::fake(function () use ($generation) {
        expect($generation->fresh()->ambiguous)->toBeTrue()
            ->and($generation->fresh()->attempt_started_at)->not->toBeNull();

        return Http::response(accepted($generation->id), 202);
    });

    $this->dispatcher->dispatchPending();
});

it('sends in FIFO order and never more than 3 at a time', function () {
    $first = pendingGeneration($this->user, ['next_attempt_at' => now()->subMinutes(4)]);
    $second = pendingGeneration($this->user, ['next_attempt_at' => now()->subMinutes(3)]);
    $third = pendingGeneration($this->user, ['next_attempt_at' => now()->subMinutes(2)]);
    $fourth = pendingGeneration($this->user, ['next_attempt_at' => now()->subMinute()]);
    $n = 0;
    Http::fake(function (Request $r) use (&$n) {
        $n++;

        return Http::response(accepted($r->header('Idempotency-Key')[0], "gen_{$n}"), 202);
    });

    $this->dispatcher->dispatchPending();

    expect($first->fresh()->provider_id)->toBe('gen_1')
        ->and($second->fresh()->provider_id)->toBe('gen_2')
        ->and($third->fresh()->provider_id)->toBe('gen_3')
        ->and($fourth->fresh()->status)->toBe(GenerationStatus::Pending);
});

it('keeps an ambiguous attempt pending and retries it with the same key', function (Closure $failure) {
    $generation = pendingGeneration($this->user);
    $calls = 0;
    Http::fake(function () use (&$calls, $failure, $generation) {
        return ++$calls === 1 ? $failure() : Http::response(accepted($generation->id), 202);
    });

    $this->dispatcher->dispatchPending();

    $fresh = $generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Pending)
        ->and($fresh->ambiguous)->toBeTrue()
        ->and($fresh->attempts)->toBe(1)
        ->and($fresh->next_attempt_at->isFuture())->toBeTrue();

    $this->travelTo($fresh->next_attempt_at->addSecond());
    $this->dispatcher->dispatchPending();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);
    $keys = Http::recorded()->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->unique()->values()->all();
    expect($keys)->toBe([$generation->id]);
})->with([
    'timeout' => [fn () => fn () => throw new ConnectionException('timeout')],
    '500' => [fn () => fn () => Http::response('boom', 500)],
    '502' => [fn () => fn () => Http::response('bad gateway', 502)],
]);

it('requeues on concurrency or rate limits without counting an attempt', function (string $code, int $retryAfter) {
    $this->freezeSecond();
    $generation = pendingGeneration($this->user);
    Http::fake(['pf.test/*' => rejected($code, 429, ['Retry-After' => (string) $retryAfter])]);

    $this->dispatcher->dispatchPending();

    $fresh = $generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Pending)
        ->and($fresh->attempts)->toBe(0)
        ->and($fresh->ambiguous)->toBeFalse()
        ->and((int) round(now()->diffInSeconds($fresh->next_attempt_at)))->toBe($retryAfter);
})->with([
    ['concurrency_limit_exceeded', 5],
    ['rate_limit_exceeded', 20],
]);

it('fails waiting generations right away and pauses until the next UTC day on the daily quota', function () {
    $generation = pendingGeneration($this->user);
    $waiting = pendingGeneration($this->user);
    $ambiguous = pendingGeneration($this->user, ['ambiguous' => true, 'next_attempt_at' => now()->addMinute()]);
    Http::fake(['pf.test/*' => rejected('daily_limit_exceeded', 429)]);

    $this->dispatcher->dispatchPending();
    $this->dispatcher->dispatchPending();

    Http::assertSentCount(1);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->fresh()->error_code)->toBe('provider_daily_quota')
        ->and($waiting->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($ambiguous->fresh()->status)->toBe(GenerationStatus::Pending)
        ->and($this->user->fresh()->balance_nano)->toBe(5_000_000_000 - $ambiguous->estimate_max_nano);
});

it('resumes after the daily pause', function () {
    $this->travelTo(Carbon::parse('2026-11-26 23:55:00', 'UTC'));
    $calls = 0;
    $next = null;
    Http::fake(function () use (&$calls, &$next) {
        return ++$calls === 1 ? rejected('daily_limit_exceeded', 429) : Http::response(accepted($next->id), 202);
    });
    pendingGeneration($this->user);
    $this->dispatcher->dispatchPending();

    $this->travelTo(now('UTC')->addDay()->startOfDay()->addSecond());
    $next = pendingGeneration($this->user);
    $this->dispatcher->dispatchPending();

    expect($next->fresh()->status)->toBe(GenerationStatus::Queued);
});

it('caps the work done in a single run', function () {
    foreach (range(1, 12) as $i) {
        pendingGeneration($this->user);
    }
    Http::fake(['pf.test/*' => Http::response('boom', 500)]);

    $this->dispatcher->dispatchPending();

    Http::assertSentCount(DispatchService::MAX_ATTEMPTS_PER_RUN);
});

it('backs off on 503 overloaded and counts the attempt', function () {
    $generation = pendingGeneration($this->user);
    Http::fake(['pf.test/*' => rejected('overloaded', 503, ['Retry-After' => '2'])]);

    $this->dispatcher->dispatchPending();

    $fresh = $generation->fresh();
    expect($fresh->attempts)->toBe(1)
        ->and($fresh->ambiguous)->toBeFalse()
        ->and($fresh->status)->toBe(GenerationStatus::Pending);
});

it('fails locally on a clear validation or policy rejection and releases the hold', function (string $code, int $status) {
    $generation = pendingGeneration($this->user);
    Http::fake(['pf.test/*' => rejected($code, $status)]);

    $this->dispatcher->dispatchPending();

    $fresh = $generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->failure_source)->toBe('local')
        ->and($fresh->error_code)->toBe($code)
        ->and($this->user->fresh()->balance_nano)->toBe(5_000_000_000);
})->with([
    ['content_policy_violation', 400],
    ['validation_failed', 422],
]);

it('reconciles instead of failing when a rejection follows an ambiguous attempt', function () {
    $generation = pendingGeneration($this->user, ['ambiguous' => true, 'attempts' => 1]);
    Http::fake([
        'pf.test/v1/generations?*' => Http::response(['object' => 'list', 'data' => [accepted($generation->id, 'gen_found', 'processing')], 'has_more' => false, 'next_cursor' => null]),
        'pf.test/v1/generations' => rejected('validation_failed', 422),
    ]);

    $this->dispatcher->dispatchPending();

    expect($generation->fresh()->provider_id)->toBe('gen_found')
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Processing);
});

it('pauses and alerts on an invalid api key without failing anything', function () {
    Exceptions::fake();
    $generation = pendingGeneration($this->user);
    Http::fake(['pf.test/*' => rejected('invalid_api_key', 401)]);

    $this->dispatcher->dispatchPending();
    $this->dispatcher->dispatchPending();

    Http::assertSentCount(1);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);
    Exceptions::assertReported(fn (ProviderRejected $e) => $e->errorCode === 'invalid_api_key');
});

it('reconciles and alerts on a reused idempotency key', function () {
    Exceptions::fake();
    $generation = pendingGeneration($this->user);
    Http::fake([
        'pf.test/v1/generations?*' => Http::response(['object' => 'list', 'data' => [accepted($generation->id, 'gen_x')], 'has_more' => false, 'next_cursor' => null]),
        'pf.test/v1/generations' => rejected('idempotency_key_reused', 409),
    ]);

    $this->dispatcher->dispatchPending();

    expect($generation->fresh()->provider_id)->toBe('gen_x');
    Exceptions::assertReported(ProviderRejected::class);
});

it('gives up after 6 failed attempts when nothing was ambiguous', function () {
    $generation = pendingGeneration($this->user, ['attempts' => 6]);
    Http::fake();

    $this->dispatcher->dispatchPending();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->fresh()->failure_source)->toBe('local');
    Http::assertNothingSent();
});

it('gives up after 15 minutes', function () {
    $generation = pendingGeneration($this->user, ['created_at' => now()->subMinutes(16)]);
    Http::fake();

    $this->dispatcher->dispatchPending();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed);
});

it('adopts the provider generation found by local_id when giving up an ambiguous one', function () {
    $generation = pendingGeneration($this->user, ['attempts' => 6, 'ambiguous' => true]);
    Http::fake(['pf.test/v1/generations?*' => Http::sequence()
        ->push(['object' => 'list', 'data' => [accepted('other', 'gen_other')], 'has_more' => true, 'next_cursor' => 'c2'])
        ->push(['object' => 'list', 'data' => [accepted($generation->id, 'gen_mine', 'processing')], 'has_more' => false, 'next_cursor' => null]),
    ]);

    $this->dispatcher->dispatchPending();

    expect($generation->fresh()->provider_id)->toBe('gen_mine')
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Processing);
});

it('fails locally an ambiguous one the provider does not know', function () {
    $generation = pendingGeneration($this->user, ['attempts' => 6, 'ambiguous' => true]);
    Http::fake(['pf.test/v1/generations?*' => Http::response(['object' => 'list', 'data' => [], 'has_more' => false, 'next_cursor' => null])]);

    $this->dispatcher->dispatchPending();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($this->user->fresh()->balance_nano)->toBe(5_000_000_000);
});

it('does not decide when the reconciliation lookup itself fails', function () {
    $generation = pendingGeneration($this->user, ['attempts' => 6, 'ambiguous' => true]);
    Http::fake(['pf.test/*' => Http::response('down', 500)]);

    $this->dispatcher->dispatchPending();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Pending)
        ->and($generation->fresh()->ambiguous)->toBeTrue();
});

it('queues the dispatcher when a generation is created', function () {
    Http::fake(['*/v1/pricing' => Http::response([], 503)]);

    app(GenerationService::class)->create($this->user, [
        'prompt' => 'hi', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [], 'form_token' => (string) Str::uuid(),
    ]);

    Queue::assertPushed(DispatchPendingGenerations::class);
});

it('queues the dispatcher when a generation reaches a final state', function () {
    $generation = pendingGeneration($this->user);

    app(GenerationStateService::class)->failLocally($generation, 'x', 'y');

    Queue::assertPushed(DispatchPendingGenerations::class);
});

it('logs the daily quota pause so a stopped queue leaves a trace', function () {
    pendingGeneration($this->user);
    Http::fake(['pf.test/*' => rejected('daily_limit_exceeded', 429)]);
    Log::spy();

    $this->dispatcher->dispatchPending();

    Log::shouldHaveReceived('warning')
        ->with('Dispatch paused: Pixelforge daily quota reached', Mockery::on(fn (array $context) => isset($context['until'])))
        ->once();
});
