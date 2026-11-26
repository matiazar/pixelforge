<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\LedgerService;
use App\Services\PollingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config(['services.pixelforge.base_url' => 'https://pf.test', 'services.pixelforge.api_key' => 'pf_key']);
    $this->polling = app(PollingService::class);
    $this->user = User::factory()->create();
    app(LedgerService::class)->topUp($this->user, 5_000_000_000);
});

function openGeneration(User $user, string $providerId, array $attributes = []): Generation
{
    $generation = Generation::factory()->for($user)->create($attributes + [
        'status' => GenerationStatus::Processing,
        'provider_id' => $providerId,
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'last_synced_at' => now()->subSeconds(20),
    ]);
    app(LedgerService::class)->hold($generation);

    return $generation;
}

function polledSuccess(Generation $generation): array
{
    return [
        'id' => $generation->provider_id,
        'status' => 'succeeded',
        'metadata' => ['local_id' => $generation->id],
        'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411],
        'image_url' => 'https://img.test/a.png',
        'completed_at' => now()->toIso8601ZuluString(),
    ];
}

it('syncs accepted generations without news for 15 seconds', function () {
    $stale = openGeneration($this->user, 'gen_stale');
    Http::fake(['pf.test/v1/generations/gen_stale' => Http::response(polledSuccess($stale))]);

    expect($this->polling->syncOpen())->toBe(1)
        ->and($stale->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('skips recently synced, pending and finished generations', function () {
    openGeneration($this->user, 'gen_recent', ['last_synced_at' => now()->subSeconds(5)]);
    openGeneration($this->user, 'gen_done', ['status' => GenerationStatus::Succeeded]);
    Generation::factory()->for($this->user)->create(['status' => GenerationStatus::Pending]);
    Http::fake();

    expect($this->polling->syncOpen())->toBe(0);
    Http::assertNothingSent();
});

it('syncs the oldest-synced first and never-synced ones too', function () {
    $never = openGeneration($this->user, 'gen_never', ['last_synced_at' => null, 'updated_at' => now()->subMinute()]);
    Http::fake(['pf.test/*' => Http::response(polledSuccess($never))]);

    $this->polling->syncOpen();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/generations/gen_never'));
});

it('stops when our API budget is used', function () {
    openGeneration($this->user, 'gen_a');
    openGeneration($this->user, 'gen_b');
    Http::fake(['pf.test/*' => Http::response(['error' => ['code' => 'rate_limit_exceeded', 'message' => 'x']], 429, ['Retry-After' => '30'])]);

    $this->polling->syncOpen();

    Http::assertSentCount(1);
});

it('keeps going when one lookup fails', function () {
    $failing = openGeneration($this->user, 'gen_fail', ['last_synced_at' => now()->subMinutes(2)]);
    $ok = openGeneration($this->user, 'gen_ok', ['last_synced_at' => now()->subMinute()]);
    Http::fake([
        'pf.test/v1/generations/gen_fail' => Http::response('boom', 500),
        'pf.test/v1/generations/gen_ok' => Http::response(polledSuccess($ok)),
    ]);

    $this->polling->syncOpen();

    expect($ok->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($failing->fresh()->last_synced_at->gt(now()->subSeconds(5)))->toBeTrue();
});

it('skips a generation whose payload cannot be applied and keeps polling the rest', function () {
    $broken = openGeneration($this->user, 'gen_broken', ['last_synced_at' => now()->subMinutes(2)]);
    $ok = openGeneration($this->user, 'gen_ok', ['last_synced_at' => now()->subMinute()]);
    Http::fake([
        'pf.test/v1/generations/gen_broken' => Http::response(array_replace(polledSuccess($broken), ['usage' => ['output_tokens' => 1]])),
        'pf.test/v1/generations/gen_ok' => Http::response(polledSuccess($ok)),
    ]);
    Exceptions::fake();

    $this->polling->syncOpen();

    Exceptions::assertReported(InvalidArgumentException::class);
    expect($ok->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($broken->fresh()->status)->toBe(GenerationStatus::Processing)
        ->and($broken->fresh()->last_synced_at->gt(now()->subSeconds(5)))->toBeTrue();
});

it('fails locally and releases the hold when the provider no longer knows the generation', function () {
    $lost = openGeneration($this->user, 'gen_lost');
    Http::fake(['pf.test/*' => Http::response(['error' => ['code' => 'not_found', 'message' => 'No such generation']], 404)]);

    $this->polling->syncOpen();

    $fresh = $lost->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->error_code)->toBe('not_found_at_provider')
        ->and($this->user->fresh()->balance_nano)->toBe(5_000_000_000);
});
