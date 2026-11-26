<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    Http::fake(['*/v1/pricing' => Http::response([], 503)]); // fallback pricing table
    $this->user = User::factory()->create();
    app(LedgerService::class)->topUp($this->user, 1_000_000_000);
});

it('lets a user with an unverified email use the dashboard', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('dashboard'))
        ->assertOk();
});

it('lists only the signed-in user generations, newest first, without provider cost', function () {
    Generation::factory()->for($this->user)->create(['created_at' => now()->subMinute()]);
    $latest = Generation::factory()->for($this->user)->create([
        'status' => GenerationStatus::Succeeded,
        'cost_nano' => 300_000_000,
        'price_nano' => 375_000_000,
        'image_url' => 'https://img.test/a.png',
        'image_url_expires_at' => now()->addHour(),
        'completed_at' => now(),
    ]);
    Generation::factory()->create(); // someone else's

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $latest->id)
            ->where('generations.data.0.status', 'succeeded')
            ->where('generations.data.0.price', '0.375')
            ->where('generations.data.0.estimate', ['min' => '0.014', 'max' => '0.02190625'])
            ->where('generations.data.0.image_url', 'https://img.test/a.png')
            ->missing('generations.data.0.cost')
            ->missing('generations.data.0.cost_nano')
            ->has('options.sizes')
            ->has('options.qualities'));
});

it('hides an expired image url', function () {
    Generation::factory()->for($this->user)->create([
        'status' => GenerationStatus::Succeeded,
        'price_nano' => 1,
        'image_url' => 'https://img.test/old.png',
        'image_url_expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.image_expired', true));
});

it('returns the price range for a draft', function () {
    $this->actingAs($this->user)
        ->postJson(route('generations.estimate'), ['prompt' => 'A lighthouse at dawn', 'size' => '1024x1024', 'quality' => 'low', 'reference_count' => 0])
        ->assertOk()
        ->assertExactJson(['min' => '0.014025', 'max' => '0.02185']);
});

it('validates the estimate input', function (array $payload, string $field) {
    $this->actingAs($this->user)
        ->postJson(route('generations.estimate'), $payload + ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low', 'reference_count' => 0])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'too many references' => [['reference_count' => 5], 'reference_count'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'prompt too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
]);

it('retries a failed generation once, with the same request and a new hold', function () {
    $failed = Generation::factory()->for($this->user)->failed()->create();

    $this->actingAs($this->user)->post(route('generations.retry', $failed))->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('generations.retry', $failed))->assertRedirect();

    $retry = Generation::where('retry_of_id', $failed->id)->sole();
    expect($retry->status)->toBe(GenerationStatus::Pending)
        ->and($retry->request_payload)->toEqual($failed->request_payload)
        ->and($retry->id)->not->toBe($failed->id)
        ->and($this->user->fresh()->balance_nano)->toBe(1_000_000_000 - $retry->estimate_max_nano);
});

it('marks a failed generation as retryable only until it is retried', function () {
    $failed = Generation::factory()->for($this->user)->failed()->create(['created_at' => now()->subMinute()]);

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('generations.data.0.can_retry', true));

    $this->actingAs($this->user)->post(route('generations.retry', $failed));

    $this->actingAs($this->user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.can_retry', false)
            ->where('generations.data.1.can_retry', false));
});

it('refuses to retry a generation that did not fail', function () {
    $succeeded = Generation::factory()->for($this->user)->create(['status' => GenerationStatus::Succeeded]);

    $this->actingAs($this->user)->post(route('generations.retry', $succeeded))->assertForbidden();
});

it('refuses to retry someone else generation', function () {
    $other = Generation::factory()->failed()->create();

    $this->actingAs($this->user)->post(route('generations.retry', $other))->assertForbidden();
    expect(Generation::whereNotNull('retry_of_id')->count())->toBe(0);
});

it('blocks a retry when the balance does not cover the maximum price', function () {
    $poor = User::factory()->create();
    $failed = Generation::factory()->for($poor)->failed()->create();

    $this->actingAs($poor)->post(route('generations.retry', $failed))->assertSessionHasErrors('balance');
});

it('prices an empty draft prompt as zero text tokens', function () {
    $this->actingAs($this->user)
        ->postJson(route('generations.estimate'), ['prompt' => '', 'size' => '1024x1024', 'quality' => 'low', 'reference_count' => 0])
        ->assertOk()
        ->assertJsonStructure(['min', 'max']);
});
