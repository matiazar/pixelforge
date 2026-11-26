<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\GenerationStateService;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->state = app(GenerationStateService::class);
    $this->user = User::factory()->create();
    app(LedgerService::class)->topUp($this->user, 1_000_000_000);
    $this->generation = Generation::factory()->for($this->user)->create(['estimate_max_nano' => 467_343_750]);
    app(LedgerService::class)->hold($this->generation);
});

function remote(string $status, array $overrides = []): array
{
    $base = [
        'id' => 'gen_abc',
        'object' => 'generation',
        'status' => $status,
        'metadata' => [],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'usage' => null,
        'error' => null,
        'image_url' => null,
        'image_url_expires_at' => null,
        'created_at' => '2026-11-26T12:00:00Z',
        'completed_at' => null,
    ];

    if ($status === 'succeeded') {
        $base['usage'] = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411];
        $base['image_url'] = 'https://img.test/gen_abc.png';
        $base['image_url_expires_at'] = '2026-11-27T12:01:00Z';
        $base['completed_at'] = '2026-11-26T12:01:00Z';
    }

    if ($status === 'failed') {
        $base['error'] = ['code' => 'generation_failed', 'message' => 'Could not generate'];
        $base['completed_at'] = '2026-11-26T12:01:00Z';
    }

    return array_replace($base, $overrides);
}

it('stores provider id and rates when the provider accepts', function () {
    $this->state->apply($this->generation, remote('queued'));

    $fresh = $this->generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Queued)
        ->and($fresh->provider_id)->toBe('gen_abc')
        ->and($fresh->rates['output_tokens'])->toBe('40.00')
        ->and($fresh->ambiguous)->toBeFalse();
});

it('charges cost plus 25% once on success', function () {
    $this->state->apply($this->generation, remote('succeeded'));

    $fresh = $this->generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->cost_nano)->toBe(305_510_000)
        ->and($fresh->price_nano)->toBe(381_887_500)
        ->and($fresh->image_url)->toBe('https://img.test/gen_abc.png')
        ->and($fresh->completed_at->toIso8601ZuluString())->toBe('2026-11-26T12:01:00Z')
        ->and($this->user->fresh()->balance_nano)->toBe(1_000_000_000 - 381_887_500);
});

it('ignores a duplicated success event', function () {
    $this->state->apply($this->generation, remote('succeeded'));
    $this->state->apply($this->generation->fresh(), remote('succeeded'));

    expect(LedgerEntry::where('generation_id', $this->generation->id)->where('type', 'charge')->count())->toBe(1)
        ->and($this->user->fresh()->balance_nano)->toBe(1_000_000_000 - 381_887_500);
});

it('never moves back when events arrive out of order', function () {
    $this->state->apply($this->generation, remote('succeeded'));
    $this->state->apply($this->generation->fresh(), remote('processing'));
    $this->state->apply($this->generation->fresh(), remote('queued'));

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('handles a webhook that arrives before the POST response', function () {
    // webhook: processing
    $this->state->apply($this->generation, remote('processing'));
    // late 202 body: queued
    $this->state->apply($this->generation->fresh(), remote('queued'));

    $fresh = $this->generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Processing)
        ->and($fresh->provider_id)->toBe('gen_abc');
});

it('releases the hold without charging when the provider reports a failure', function () {
    $this->state->apply($this->generation, remote('failed'));

    $fresh = $this->generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->failure_source)->toBe('provider')
        ->and($fresh->error_code)->toBe('generation_failed')
        ->and($fresh->price_nano)->toBe(0)
        ->and($this->user->fresh()->balance_nano)->toBe(1_000_000_000);
});

it('keeps provider final states immutable', function () {
    $this->state->apply($this->generation, remote('failed'));
    $this->state->apply($this->generation->fresh(), remote('succeeded'));

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($this->user->fresh()->balance_nano)->toBe(1_000_000_000);
});

it('lets a provider success overturn a provisional local failure, charging once', function () {
    $this->state->failLocally($this->generation, 'dispatch_gave_up', 'Could not reach the provider');
    expect($this->user->fresh()->balance_nano)->toBe(1_000_000_000);

    $this->state->apply($this->generation->fresh(), remote('succeeded'));

    $fresh = $this->generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->failure_source)->toBeNull()
        ->and($this->user->fresh()->balance_nano)->toBe(1_000_000_000 - 381_887_500);
});

it('does not fail locally a generation that already finished', function () {
    $this->state->apply($this->generation, remote('succeeded'));

    $this->state->failLocally($this->generation->fresh(), 'dispatch_gave_up', 'x');

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('uses the rates fixed at creation, not later ones', function () {
    $this->state->apply($this->generation, remote('queued'));

    $this->state->apply($this->generation->fresh(), remote('succeeded', [
        'rates' => ['input_text_tokens' => '50.00', 'input_image_tokens' => '100.00', 'output_tokens' => '400.00', 'reasoning_tokens' => '125.00'],
    ]));

    expect($this->generation->fresh()->cost_nano)->toBe(305_510_000);
});

it('charges the real price even when it exceeds the hold', function () {
    $small = Generation::factory()->for($this->user)->create(['estimate_max_nano' => 100_000_000]);
    app(LedgerService::class)->hold($small);

    $this->state->apply($small, remote('succeeded', ['id' => 'gen_small']));

    expect($small->fresh()->price_nano)->toBe(381_887_500);
});

it('refuses a provider payload that belongs to another generation', function () {
    $this->state->apply($this->generation, remote('queued', ['metadata' => ['local_id' => 'someone_else']]));
})->throws(InvalidArgumentException::class);

it('refuses a payload whose provider id differs from the stored one', function () {
    $this->state->apply($this->generation, remote('queued'));

    $this->state->apply($this->generation->fresh(), remote('succeeded', ['id' => 'gen_other']));
})->throws(InvalidArgumentException::class);
