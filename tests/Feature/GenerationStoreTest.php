<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\Setting;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    Queue::fake();
    Http::fake(['*/v1/pricing' => Http::response([], 503)]);
    $this->user = User::factory()->create();
    app(LedgerService::class)->topUp($this->user, 1_000_000_000);
});

function storePayload(array $overrides = []): array
{
    return $overrides + [
        'prompt' => 'A lighthouse at dawn',
        'size' => '1024x1024',
        'quality' => 'low',
        'reference_images' => [],
        'form_token' => (string) Str::uuid(),
    ];
}

it('requires authentication', function () {
    $this->post(route('generations.store'), storePayload())->assertRedirect(route('login'));
});

it('creates a generation for the signed-in user', function () {
    $this->actingAs($this->user)
        ->post(route('generations.store'), storePayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Generation::where('user_id', $this->user->id)->count())->toBe(1);
});

it('tells the user when the request waits in the queue', function () {
    Generation::factory()->count(3)->create(['status' => GenerationStatus::Processing]);

    $this->actingAs($this->user)
        ->post(route('generations.store'), storePayload())
        ->assertSessionHas('status', 'Your request is in the queue');
});

it('validates the input', function (array $bad, string $field) {
    $this->actingAs($this->user)
        ->post(route('generations.store'), storePayload($bad))
        ->assertSessionHasErrors($field);

    expect(Generation::count())->toBe(0);
})->with([
    'empty prompt' => [['prompt' => ''], 'prompt'],
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'five references' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'non http reference' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
    'missing form token' => [['form_token' => ''], 'form_token'],
]);

it('shows how much is missing when the balance is not enough', function () {
    $poor = User::factory()->create();
    app(LedgerService::class)->topUp($poor, 400_000_000);

    // Max price: high, 1536x1024, 4000 chars, 4 references = 0.4929375
    $this->actingAs($poor)
        ->post(route('generations.store'), storePayload([
            'quality' => 'high',
            'prompt' => str_repeat('a', 4000),
            'reference_images' => array_fill(0, 4, 'https://example.com/a.png'),
            'size' => '1536x1024',
        ]))
        ->assertSessionHasErrors(['balance' => 'Not enough credit. You need $0.0929375 more.']);
});

it('shows the daily limit message', function () {
    Setting::put('daily_limit', '0');

    $this->actingAs($this->user)
        ->post(route('generations.store'), storePayload())
        ->assertSessionHasErrors('daily_limit');
});

it('accepts a request without the reference images field', function () {
    $payload = storePayload();
    unset($payload['reference_images']);

    $this->actingAs($this->user)
        ->post(route('generations.store'), $payload)
        ->assertSessionHasNoErrors();

    expect(Generation::first()->request_payload['reference_images'])->toBe([]);
});
