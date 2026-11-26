<?php

use App\Enums\GenerationStatus;
use App\Exceptions\DailyLimitReached;
use App\Exceptions\InsufficientBalance;
use App\Models\Generation;
use App\Models\Setting;
use App\Models\User;
use App\Services\GenerationService;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    Http::fake(['*/v1/pricing' => Http::response([], 503)]); // fallback table = documented rates
    $this->service = app(GenerationService::class);
    $this->user = User::factory()->create();
    app(LedgerService::class)->topUp($this->user, 1_000_000_000);
});

function generationInput(array $overrides = []): array
{
    return $overrides + [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        'form_token' => (string) Str::uuid(),
    ];
}

it('creates a pending generation and holds the maximum price', function () {
    $generation = $this->service->create($this->user, generationInput());

    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->estimate_max_nano)->toBe(467_343_750)
        ->and($generation->estimate_min_nano)->toBe(357_781_250)
        ->and($this->user->fresh()->balance_nano)->toBe(1_000_000_000 - 467_343_750);
});

it('stores the exact payload that will be sent to the provider', function () {
    $generation = $this->service->create($this->user, generationInput());

    expect($generation->request_payload)->toBe([
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ]);
});

it('refuses when the balance does not cover the maximum price', function () {
    $poor = User::factory()->create();
    app(LedgerService::class)->topUp($poor, 400_000_000);

    try {
        $this->service->create($poor, generationInput());
        $this->fail('Expected InsufficientBalance');
    } catch (InsufficientBalance $e) {
        expect($e->shortfallNano)->toBe(67_343_750)
            ->and(Generation::count())->toBe(0)
            ->and($poor->fresh()->balance_nano)->toBe(400_000_000);
    }
});

it('returns the same generation when the form is submitted twice', function () {
    $input = generationInput();

    $first = $this->service->create($this->user, $input);
    $second = $this->service->create($this->user, $input);

    expect($second->id)->toBe($first->id)
        ->and(Generation::count())->toBe(1)
        ->and($this->user->fresh()->balance_nano)->toBe(1_000_000_000 - 467_343_750);
});

it('refuses after the daily limit of the user', function () {
    Setting::put('daily_limit', '2');
    $cheap = ['quality' => 'low', 'size' => '1024x1024', 'reference_images' => []];

    $this->service->create($this->user, generationInput($cheap));
    $this->service->create($this->user, generationInput($cheap));

    $this->service->create($this->user, generationInput($cheap));
})->throws(DailyLimitReached::class);

it('counts the daily limit per UTC day', function () {
    Setting::put('daily_limit', '1');
    $cheap = ['quality' => 'low', 'size' => '1024x1024', 'reference_images' => []];
    Generation::factory()->for($this->user)->create(['created_at' => now('UTC')->subDay()]);

    $generation = $this->service->create($this->user, generationInput($cheap));

    expect($generation->exists)->toBeTrue();
});

it('tells whether a provider slot is free', function () {
    expect($this->service->hasFreeSlot())->toBeTrue();

    Generation::factory()->count(3)->create(['status' => GenerationStatus::Processing]);

    expect($this->service->hasFreeSlot())->toBeFalse();
});

it('refuses a form token that belongs to another user without crashing', function () {
    $input = generationInput(['quality' => 'low', 'size' => '1024x1024', 'reference_images' => []]);
    $other = User::factory()->create();
    app(LedgerService::class)->topUp($other, 1_000_000_000);
    $this->service->create($other, $input);

    $mine = $this->service->create($this->user, $input);

    expect($mine->user_id)->toBe($this->user->id)
        ->and(Generation::count())->toBe(2);
});

it('does not count generations in backoff as ahead in the queue', function () {
    Generation::factory()->count(3)->create(['next_attempt_at' => now()->addMinutes(2)]);

    $generation = $this->service->create($this->user, generationInput(['quality' => 'low', 'size' => '1024x1024', 'reference_images' => []]));

    expect($this->service->waitsForSlot($generation))->toBeFalse();
});
