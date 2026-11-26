<?php

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;

it('gives users a zero balance and no admin flag by default', function () {
    $user = User::factory()->create()->fresh();

    expect($user->balance_nano)->toBe(0)
        ->and($user->is_admin)->toBeFalse();
});

it('uses a ULID as the generation id', function () {
    $generation = Generation::factory()->create();

    expect($generation->id)->toMatch('/^[0-9a-z]{26}$/i');
});

it('rejects two generations of the same user with the same form token', function () {
    $generation = Generation::factory()->create();

    Generation::factory()->create(['form_token' => $generation->form_token, 'user_id' => $generation->user_id]);
})->throws(UniqueConstraintViolationException::class);

it('rejects two retries of the same generation', function () {
    $failed = Generation::factory()->failed()->create();
    Generation::factory()->create(['retry_of_id' => $failed->id]);

    Generation::factory()->create(['retry_of_id' => $failed->id]);
})->throws(UniqueConstraintViolationException::class);

it('rejects two generations with the same provider id', function () {
    Generation::factory()->create(['provider_id' => 'gen_123']);

    Generation::factory()->create(['provider_id' => 'gen_123']);
})->throws(UniqueConstraintViolationException::class);

it('rejects a second ledger entry of the same type for a generation', function () {
    $generation = Generation::factory()->create();
    LedgerEntry::create(['user_id' => $generation->user_id, 'generation_id' => $generation->id, 'type' => 'charge', 'amount_nano' => -10]);

    LedgerEntry::create(['user_id' => $generation->user_id, 'generation_id' => $generation->id, 'type' => 'charge', 'amount_nano' => -10]);
})->throws(UniqueConstraintViolationException::class);

it('allows many top-ups without a generation', function () {
    $user = User::factory()->create();

    LedgerEntry::create(['user_id' => $user->id, 'type' => 'topup', 'amount_nano' => 5]);
    LedgerEntry::create(['user_id' => $user->id, 'type' => 'topup', 'amount_nano' => 5]);

    expect(LedgerEntry::where('user_id', $user->id)->count())->toBe(2);
});

it('rejects a duplicated webhook event id', function () {
    WebhookEvent::create(['event_id' => 'evt_1', 'type' => 'ping', 'payload' => [], 'received_at' => now()]);

    WebhookEvent::create(['event_id' => 'evt_1', 'type' => 'ping', 'payload' => [], 'received_at' => now()]);
})->throws(UniqueConstraintViolationException::class);

it('stores money columns as exact 64-bit integers', function () {
    $generation = Generation::factory()->create(['estimate_max_nano' => 9_000_000_000_000]);

    expect($generation->fresh()->estimate_max_nano)->toBe(9_000_000_000_000);
});
