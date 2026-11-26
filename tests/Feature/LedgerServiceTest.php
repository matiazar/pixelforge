<?php

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\LedgerService;

beforeEach(function () {
    $this->ledger = app(LedgerService::class);
    $this->user = User::factory()->create();
    $this->ledger->topUp($this->user, 1_000_000_000); // 1 USD
});

function ledgerSum(User $user): int
{
    return (int) LedgerEntry::where('user_id', $user->id)->sum('amount_nano');
}

it('tops up the balance and records the entry', function () {
    expect($this->user->fresh()->balance_nano)->toBe(1_000_000_000)
        ->and(LedgerEntry::where('type', 'topup')->count())->toBe(1);
});

it('rejects a top-up that is not positive', function (int $amount) {
    $this->ledger->topUp($this->user, $amount);
})->with([0, -5])->throws(InvalidArgumentException::class);

it('holds the maximum estimated price', function () {
    $generation = Generation::factory()->for($this->user)->create(['estimate_max_nano' => 400_000_000]);

    $this->ledger->hold($generation);

    expect($this->user->fresh()->balance_nano)->toBe(600_000_000);
});

it('releases the hold and charges the real price on success', function () {
    $generation = Generation::factory()->for($this->user)->create(['estimate_max_nano' => 400_000_000]);
    $this->ledger->hold($generation);

    $this->ledger->settle($generation, 300_000_000);

    expect($this->user->fresh()->balance_nano)->toBe(700_000_000);
});

it('only releases the hold when the generation failed', function () {
    $generation = Generation::factory()->for($this->user)->create(['estimate_max_nano' => 400_000_000]);
    $this->ledger->hold($generation);

    $this->ledger->settle($generation, null);

    expect($this->user->fresh()->balance_nano)->toBe(1_000_000_000)
        ->and(LedgerEntry::where('type', 'charge')->count())->toBe(0);
});

it('never charges twice for the same generation', function () {
    $generation = Generation::factory()->for($this->user)->create(['estimate_max_nano' => 400_000_000]);
    $this->ledger->hold($generation);

    $this->ledger->settle($generation, 300_000_000);
    $this->ledger->settle($generation, 300_000_000);

    expect($this->user->fresh()->balance_nano)->toBe(700_000_000)
        ->and(LedgerEntry::where('generation_id', $generation->id)->count())->toBe(3);
});

it('charges once when a local failure is later reported as succeeded', function () {
    $generation = Generation::factory()->for($this->user)->create(['estimate_max_nano' => 400_000_000]);
    $this->ledger->hold($generation);

    $this->ledger->settle($generation, null);
    $this->ledger->settle($generation, 300_000_000);

    expect($this->user->fresh()->balance_nano)->toBe(700_000_000);
});

it('charges the real price even above the hold, leaving a negative balance', function () {
    $generation = Generation::factory()->for($this->user)->create(['estimate_max_nano' => 1_000_000_000]);
    $this->ledger->hold($generation);

    $this->ledger->settle($generation, 1_200_000_000);

    expect($this->user->fresh()->balance_nano)->toBe(-200_000_000);
});

it('keeps the cached balance equal to the ledger sum', function () {
    $generation = Generation::factory()->for($this->user)->create(['estimate_max_nano' => 400_000_000]);
    $this->ledger->hold($generation);
    $this->ledger->settle($generation, 123_456_789);

    expect($this->user->fresh()->balance_nano)->toBe(ledgerSum($this->user));
});
