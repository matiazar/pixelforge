<?php

use App\Console\Commands\CreateDemoGenerations;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    Http::fake(['*/v1/pricing' => Http::response([], 503)]); // fallback table = documented rates
    config(['app.seed.demo_email' => 'demo@pixelforge.test']);
});

function demoUser(int $creditNano = 20_000_000_000): User
{
    $user = User::factory()->create(['email' => 'demo@pixelforge.test']);
    app(LedgerService::class)->topUp($user, $creditNano);

    return $user;
}

it('queues the sample generations for the demo account, including one that fails on purpose', function () {
    $demo = demoUser();

    $this->artisan('pixelforge:demo')->assertSuccessful();

    $generations = Generation::where('user_id', $demo->id)->get();
    expect($generations)->toHaveCount(count(CreateDemoGenerations::SAMPLES))
        ->and($generations->every(fn (Generation $g) => $g->status === GenerationStatus::Pending))->toBeTrue()
        ->and($generations->contains(fn (Generation $g) => str_starts_with($g->request_payload['prompt'], '[fail]')))->toBeTrue();
});

it('does not queue the samples twice when run again', function () {
    $demo = demoUser();

    $this->artisan('pixelforge:demo')->assertSuccessful();
    $this->artisan('pixelforge:demo')->assertSuccessful();

    expect(Generation::where('user_id', $demo->id)->count())->toBe(count(CreateDemoGenerations::SAMPLES));
});

it('fails with a clear message when the demo account does not exist', function () {
    $this->artisan('pixelforge:demo')
        ->expectsOutputToContain('Run php artisan db:seed first')
        ->assertFailed();

    expect(Generation::count())->toBe(0);
});

it('stops and says why when the demo balance runs out', function () {
    demoUser(creditNano: 30_000_000); // enough for the first low-quality sample only

    $this->artisan('pixelforge:demo')
        ->expectsOutputToContain('Not enough balance')
        ->assertFailed();

    expect(Generation::count())->toBe(1);
});
