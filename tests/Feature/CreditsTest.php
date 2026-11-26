<?php

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
});

it('requires authentication', function () {
    $this->get(route('credits.index'))->assertRedirect(route('login'));
    $this->post(route('credits.store'), ['pack' => '5'])->assertRedirect(route('login'));
});

it('shows the balance, the packs and only the user own movements, newest first', function () {
    $ledger = app(LedgerService::class);
    $ledger->topUp($this->user, 5_000_000_000);
    $generation = Generation::factory()->for($this->user)->create(['estimate_max_nano' => 21_906_250]);
    $ledger->hold($generation);
    $ledger->topUp(User::factory()->create(), 1_000_000_000); // someone else's

    $this->actingAs($this->user->fresh())
        ->get(route('credits.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Credits')
            ->where('balance', '4.97809375')
            ->where('packs', ['5', '10', '20'])
            ->has('movements.data', 2)
            ->where('movements.data.0.type', 'hold')
            ->where('movements.data.0.amount', '-0.02190625')
            ->where('movements.data.0.prompt', 'A lighthouse at dawn')
            ->where('movements.data.1.type', 'topup')
            ->where('movements.data.1.amount', '5.00'));
});

it('adds a fictitious credit pack to the balance', function (string $pack, int $nano) {
    $this->actingAs($this->user->fresh())
        ->post(route('credits.store'), ['pack' => $pack, 'form_token' => (string) Str::uuid()])
        ->assertRedirect()
        ->assertSessionHas('status', "Added \${$pack}.00 to your balance");

    expect($this->user->fresh()->balance_nano)->toBe($nano)
        ->and(LedgerEntry::where('user_id', $this->user->id)->where('type', 'topup')->sum('amount_nano'))->toEqual($nano);
})->with([
    ['5', 5_000_000_000],
    ['10', 10_000_000_000],
    ['20', 20_000_000_000],
]);

it('rejects amounts that are not a pack', function (mixed $pack) {
    $this->actingAs($this->user->fresh())
        ->post(route('credits.store'), ['pack' => $pack, 'form_token' => (string) Str::uuid()])
        ->assertSessionHasErrors('pack');

    expect($this->user->fresh()->balance_nano)->toBe(0);
})->with(['1000000', '-5', '5.5', '', null]);

it('shares the formatted balance on every page', function () {
    app(LedgerService::class)->topUp($this->user, 1_500_000_000);

    $this->actingAs($this->user->fresh())
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('balance', '1.50'));
});

it('shows a negative balance as negative', function () {
    $this->user->forceFill(['balance_nano' => -250_000_000])->save();

    $this->actingAs($this->user->fresh())
        ->get(route('credits.index'))
        ->assertInertia(fn (Assert $page) => $page->where('balance', '-0.25'));
});

it('adds a pack only once when the same form is sent twice', function () {
    $token = (string) Str::uuid();

    $this->actingAs($this->user->fresh())->post(route('credits.store'), ['pack' => '5', 'form_token' => $token]);
    $this->actingAs($this->user->fresh())->post(route('credits.store'), ['pack' => '5', 'form_token' => $token]);

    expect($this->user->fresh()->balance_nano)->toBe(5_000_000_000)
        ->and(LedgerEntry::where('user_id', $this->user->id)->count())->toBe(1);
});

it('requires a form token to buy credits', function () {
    $this->actingAs($this->user->fresh())
        ->post(route('credits.store'), ['pack' => '5'])
        ->assertSessionHasErrors('form_token');

    expect($this->user->fresh()->balance_nano)->toBe(0);
});

it('lets two users use the same form token', function () {
    $token = (string) Str::uuid();
    $other = User::factory()->create();

    $this->actingAs($this->user->fresh())->post(route('credits.store'), ['pack' => '5', 'form_token' => $token]);
    $this->actingAs($other->fresh())->post(route('credits.store'), ['pack' => '5', 'form_token' => $token]);

    expect($other->fresh()->balance_nano)->toBe(5_000_000_000);
});
