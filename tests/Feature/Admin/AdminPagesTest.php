<?php

use App\Enums\GenerationStatus;
use App\Jobs\DispatchPendingGenerations;
use App\Jobs\SyncOpenGenerations;
use App\Models\Generation;
use App\Models\Setting;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake();
    config(['services.pixelforge.base_url' => 'https://pf.test', 'services.pixelforge.api_key' => 'pf_key']);
    $this->admin = User::factory()->create(['name' => 'Admin']);
    $this->admin->forceFill(['is_admin' => true])->save();
    $this->user = User::factory()->create(['name' => 'Demo', 'email' => 'demo@example.com']);
});

function chargedGeneration(User $user, string $completedAtUtc, int $cost, int $price): Generation
{
    return Generation::factory()->for($user)->create([
        'status' => GenerationStatus::Succeeded,
        'cost_nano' => $cost,
        'price_nano' => $price,
        'completed_at' => CarbonImmutable::parse($completedAtUtc, 'UTC'),
        'api_key_fingerprint' => app(PixelforgeClient::class)->apiKeyFingerprint(),
    ]);
}

dataset('admin routes', [
    'report' => ['get', 'admin.report'],
    'reconciliation' => ['get', 'admin.reconciliation'],
    'sync pending' => ['post', 'admin.reconciliation.sync'],
    'settings' => ['get', 'admin.settings.edit'],
    'update settings' => ['put', 'admin.settings.update'],
]);

it('sends guests to login', function (string $method, string $route) {
    $this->{$method}(route($route))->assertRedirect(route('login'));
})->with('admin routes');

it('forbids non-admin users', function (string $method, string $route) {
    Http::fake();

    $this->actingAs($this->user)->{$method}(route($route), ['timezone' => 'UTC', 'daily_limit' => 5, 'registration_open' => false])
        ->assertForbidden();

    Http::assertNothingSent();
    expect(Setting::query()->count())->toBe(0);
})->with('admin routes');

it('shows the report by day in the settings timezone and by user, as formatted strings', function () {
    Setting::put('timezone', 'America/Argentina/Buenos_Aires');
    chargedGeneration($this->user, '2026-11-26 02:30:00', 100_000_000, 125_000_000); // 25th in Buenos Aires
    chargedGeneration($this->user, '2026-11-26 15:00:00', 373_875_000, 467_343_750);

    $this->actingAs($this->admin)
        ->get(route('admin.report', ['from' => '2026-11-25', 'to' => '2026-11-26']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('timezone', 'America/Argentina/Buenos_Aires')
            ->where('filters', ['from' => '2026-11-25', 'to' => '2026-11-26'])
            ->has('days', 2)
            ->where('days.0', ['date' => '2026-11-25', 'generations' => 1, 'charged' => '0.125', 'cost' => '0.10', 'profit' => '0.025'])
            ->where('days.1', ['date' => '2026-11-26', 'generations' => 1, 'charged' => '0.46734375', 'cost' => '0.373875', 'profit' => '0.09346875'])
            ->where('users.0', ['name' => 'Demo', 'email' => 'demo@example.com', 'generations' => 2, 'charged' => '0.59234375', 'cost' => '0.473875', 'profit' => '0.11846875'])
            ->where('totals', ['generations' => 2, 'charged' => '0.59234375', 'cost' => '0.473875', 'profit' => '0.11846875']));
});

it('defaults the report to the last 30 days in the settings timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 01:00:00', 'UTC'));
    Setting::put('timezone', 'America/Argentina/Buenos_Aires');

    $this->actingAs($this->admin)
        ->get(route('admin.report'))
        ->assertInertia(fn (Assert $page) => $page->where('filters', ['from' => '2026-10-27', 'to' => '2026-11-25']));
});

it('rejects a report range that is reversed or too long', function (array $query) {
    $this->actingAs($this->admin)->get(route('admin.report', $query))->assertSessionHasErrors();
})->with([
    'reversed' => [['from' => '2026-11-26', 'to' => '2026-11-01']],
    'over a year' => [['from' => '2025-01-01', 'to' => '2026-11-26']],
    'not a date' => [['from' => 'yesterday', 'to' => '2026-11-26']],
    'only from' => [['from' => '2026-11-01']],
    'only to' => [['to' => '2026-11-01']],
]);

it('shows the reconciliation per UTC day with the exact difference', function () {
    chargedGeneration($this->user, '2026-11-26 10:00:00', 104_770_000, 130_962_500);
    Http::fake(['pf.test/v1/usage*' => Http::response([
        'object' => 'usage',
        'data' => [['date' => '2026-11-26', 'generations' => 2, 'cost_usd' => '0.104770001']],
        'total' => ['generations' => 2, 'cost_usd' => '0.104770001'],
    ])]);

    $this->actingAs($this->admin)
        ->get(route('admin.reconciliation', ['from' => '2026-11-26', 'to' => '2026-11-26']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Reconciliation')
            ->where('error', null)
            ->where('open', 0)
            ->where('rows.0', [
                'date' => '2026-11-26',
                'our_generations' => 1,
                'provider_generations' => 2,
                'our_cost' => '0.10477',
                'provider_cost' => '0.104770001',
                'difference' => '-0.000000001',
                'matches' => false,
            ]));
});

it('says the provider could not be reached instead of showing a match', function () {
    Http::fake(['pf.test/v1/usage*' => Http::response('', 502)]);

    $this->actingAs($this->admin)
        ->get(route('admin.reconciliation'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows', [])
            ->where('error', 'Pixelforge usage could not be loaded. Try again in a moment.'));
});

it('shows an error when the provider usage has an amount we cannot read exactly', function () {
    Http::fake(['pf.test/v1/usage*' => Http::response([
        'object' => 'usage',
        'data' => [['date' => '2026-11-26', 'generations' => 1, 'cost_usd' => '0.1047700001']],
    ])]);

    $this->actingAs($this->admin)
        ->get(route('admin.reconciliation', ['from' => '2026-11-26', 'to' => '2026-11-26']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows', [])
            ->where('error', 'Pixelforge usage could not be read exactly. The admin was notified.'));
});

it('rejects a reconciliation range over the provider maximum of 92 days', function () {
    Http::fake();

    $this->actingAs($this->admin)
        ->get(route('admin.reconciliation', ['from' => '2026-01-01', 'to' => '2026-11-26']))
        ->assertSessionHasErrors('to');

    Http::assertNothingSent();
});

it('queues a forced sync and the dispatcher instead of calling Pixelforge in the request', function () {
    Http::fake();

    $this->actingAs($this->admin)
        ->post(route('admin.reconciliation.sync'))
        ->assertRedirect()
        ->assertSessionHas('status', 'Sync started: open generations and ambiguous sends are being checked with Pixelforge in the background.');

    Http::assertNothingSent();
    Queue::assertPushed(SyncOpenGenerations::class, fn (SyncOpenGenerations $job) => $job->force === true);
    Queue::assertPushed(DispatchPendingGenerations::class);
});

it('allows one sync per minute so it cannot use up the shared Pixelforge budget', function () {
    $this->actingAs($this->admin)->post(route('admin.reconciliation.sync'))->assertRedirect();

    // A second click inside the minute is refused with a message on the page, not a bare 429 screen.
    $this->actingAs($this->admin)
        ->from(route('admin.reconciliation'))
        ->post(route('admin.reconciliation.sync'))
        ->assertRedirect(route('admin.reconciliation'))
        ->assertSessionHas('status', fn (string $status) => str_starts_with($status, 'A sync already ran in the last minute.'));

    Queue::assertPushed(SyncOpenGenerations::class, 1);
    Queue::assertPushed(DispatchPendingGenerations::class, 1);
});

it('does not mark a day as matching when the cost matches but the count does not', function () {
    chargedGeneration($this->user, '2026-11-26 10:00:00', 104_770_000, 130_962_500);
    Http::fake(['pf.test/v1/usage*' => Http::response([
        'object' => 'usage',
        'data' => [['date' => '2026-11-26', 'generations' => 2, 'cost_usd' => '0.104770000']],
        'total' => ['generations' => 2, 'cost_usd' => '0.104770000'],
    ])]);

    $this->actingAs($this->admin)
        ->get(route('admin.reconciliation', ['from' => '2026-11-26', 'to' => '2026-11-26']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.difference', '0.00')
            ->where('rows.0.matches', false));
});

it('shows the global settings with their defaults', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Settings')
            ->where('settings', ['timezone' => 'UTC', 'daily_limit' => 20, 'registration_open' => true, 'markup_percent' => 25])
            ->has('timezones'));
});

it('updates the global settings', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.settings.update'), [
            'timezone' => 'America/Argentina/Buenos_Aires',
            'daily_limit' => 7,
            'registration_open' => false,
            'markup_percent' => 40,
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Settings saved.');

    expect(Setting::get('timezone'))->toBe('America/Argentina/Buenos_Aires')
        ->and(Setting::get('daily_limit'))->toBe('7')
        ->and(Setting::get('registration_open'))->toBe('0')
        ->and(Setting::get('markup_percent'))->toBe('40');
});

it('rejects invalid settings', function (array $data, string $field) {
    $this->actingAs($this->admin)
        ->put(route('admin.settings.update'), $data + ['timezone' => 'UTC', 'daily_limit' => 5, 'registration_open' => true, 'markup_percent' => 25])
        ->assertSessionHasErrors($field);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'unknown timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'zero limit' => [['daily_limit' => 0], 'daily_limit'],
    'huge limit' => [['daily_limit' => 100_000], 'daily_limit'],
    'decimal limit' => [['daily_limit' => '2.5'], 'daily_limit'],
    'not a boolean' => [['registration_open' => 'maybe'], 'registration_open'],
    'negative markup' => [['markup_percent' => -1], 'markup_percent'],
    'markup over 100' => [['markup_percent' => 101], 'markup_percent'],
    'decimal markup' => [['markup_percent' => '12.5'], 'markup_percent'],
    'missing markup' => [['markup_percent' => null], 'markup_percent'],
]);

it('closes registration when the setting says so', function () {
    Setting::put('registration_open', '0');

    $this->get(route('register'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Registration is closed.');

    $this->post(route('register.store'), [
        'name' => 'Late',
        'email' => 'late@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertForbidden();

    $this->assertGuest();
    expect(User::where('email', 'late@example.com')->exists())->toBeFalse();
});

it('tells the pages whether registration is open', function () {
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('registrationOpen', true));

    Setting::put('registration_open', '0');

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('registrationOpen', false));
});
