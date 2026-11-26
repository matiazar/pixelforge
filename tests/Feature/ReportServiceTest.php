<?php

use App\Enums\GenerationStatus;
use App\Exceptions\ProviderUnavailable;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config(['services.pixelforge.base_url' => 'https://pf.test', 'services.pixelforge.api_key' => 'pf_key']);
    $this->report = app(ReportService::class);
    $this->ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
    $this->bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
});

function settled(User $user, string $completedAtUtc, int $cost, int $price, array $attributes = []): Generation
{
    return Generation::factory()->for($user)->create($attributes + [
        'status' => GenerationStatus::Succeeded,
        'cost_nano' => $cost,
        'price_nano' => $price,
        'completed_at' => CarbonImmutable::parse($completedAtUtc, 'UTC'),
        'api_key_fingerprint' => app(PixelforgeClient::class)->apiKeyFingerprint(),
    ]);
}

it('groups charged, provider cost and profit by day in the given timezone', function () {
    // 02:30 UTC on the 26th is still the 25th in Buenos Aires (UTC-3).
    settled($this->ana, '2026-11-26 02:30:00', 100_000_000, 125_000_000);
    settled($this->ana, '2026-11-26 04:00:00', 200_000_000, 250_000_000);
    settled($this->bob, '2026-11-26 20:00:00', 1, 2);

    $days = $this->report->byDay(CarbonImmutable::parse('2026-11-25'), CarbonImmutable::parse('2026-11-26'), 'America/Argentina/Buenos_Aires');

    expect($days)->toBe([
        ['date' => '2026-11-25', 'generations' => 1, 'price_nano' => 125_000_000, 'cost_nano' => 100_000_000, 'profit_nano' => 25_000_000],
        ['date' => '2026-11-26', 'generations' => 2, 'price_nano' => 250_000_002, 'cost_nano' => 200_000_001, 'profit_nano' => 50_000_001],
    ]);
});

it('keeps the timezone range edges exact', function () {
    // Range 2026-11-26 in Buenos Aires = 03:00 UTC on the 26th to 03:00 UTC on the 27th.
    settled($this->ana, '2026-11-26 02:59:59', 1, 2);
    settled($this->ana, '2026-11-26 03:00:00', 10, 20);
    settled($this->ana, '2026-11-27 02:59:59', 100, 200);
    settled($this->ana, '2026-11-27 03:00:00', 1000, 2000);

    $day = CarbonImmutable::parse('2026-11-26');
    $days = $this->report->byDay($day, $day, 'America/Argentina/Buenos_Aires');

    expect($days)->toBe([
        ['date' => '2026-11-26', 'generations' => 2, 'price_nano' => 220, 'cost_nano' => 110, 'profit_nano' => 110],
    ]);
});

it('ignores generations that were not charged', function () {
    Generation::factory()->for($this->ana)->failed()->create(['completed_at' => '2026-11-26 10:00:00']);
    Generation::factory()->for($this->ana)->create(['status' => GenerationStatus::Processing]);

    $day = CarbonImmutable::parse('2026-11-26');

    expect($this->report->byDay($day, $day, 'UTC'))->toBe([])
        ->and($this->report->byUser($day, $day, 'UTC'))->toBe([]);
});

it('groups by user, highest charged first', function () {
    settled($this->ana, '2026-11-26 10:00:00', 100, 125);
    settled($this->bob, '2026-11-26 11:00:00', 400, 500);
    settled($this->bob, '2026-11-26 12:00:00', 400, 500);

    $day = CarbonImmutable::parse('2026-11-26');

    expect($this->report->byUser($day, $day, 'UTC'))->toBe([
        ['user_id' => $this->bob->id, 'name' => 'Bob', 'email' => 'bob@example.com', 'generations' => 2, 'price_nano' => 1000, 'cost_nano' => 800, 'profit_nano' => 200],
        ['user_id' => $this->ana->id, 'name' => 'Ana', 'email' => 'ana@example.com', 'generations' => 1, 'price_nano' => 125, 'cost_nano' => 100, 'profit_nano' => 25],
    ]);
});

it('reconciles our cost per UTC day against the provider usage, current key only', function () {
    settled($this->ana, '2026-11-25 23:59:59', 104_770_000, 130_962_500);
    settled($this->ana, '2026-11-26 00:00:01', 50_000_000, 62_500_000);
    settled($this->bob, '2026-11-26 09:00:00', 999_000_000, 1_248_750_000, ['api_key_fingerprint' => 'another-key-0000']);

    Http::fake(['pf.test/v1/usage*' => Http::response([
        'object' => 'usage',
        'start_date' => '2026-11-25',
        'end_date' => '2026-11-26',
        'data' => [
            ['date' => '2026-11-25', 'generations' => 1, 'cost_usd' => '0.104770000'],
            ['date' => '2026-11-26', 'generations' => 2, 'cost_usd' => '0.050000001'],
        ],
        'total' => ['generations' => 3, 'cost_usd' => '0.154770001'],
    ])]);

    $rows = $this->report->reconciliation(CarbonImmutable::parse('2026-11-25'), CarbonImmutable::parse('2026-11-26'));

    expect($rows)->toBe([
        ['date' => '2026-11-25', 'our_generations' => 1, 'provider_generations' => 1, 'our_cost_nano' => 104_770_000, 'provider_cost_nano' => 104_770_000, 'difference_nano' => 0],
        ['date' => '2026-11-26', 'our_generations' => 1, 'provider_generations' => 2, 'our_cost_nano' => 50_000_000, 'provider_cost_nano' => 50_000_001, 'difference_nano' => -1],
    ]);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'start_date=2026-11-25') && str_contains($r->url(), 'end_date=2026-11-26'));
});

it('shows our cost on a day the provider did not return', function () {
    settled($this->ana, '2026-11-26 10:00:00', 7, 9);

    Http::fake(['pf.test/v1/usage*' => Http::response(['object' => 'usage', 'data' => [], 'total' => ['generations' => 0, 'cost_usd' => '0.000000000']])]);

    $day = CarbonImmutable::parse('2026-11-26');

    expect($this->report->reconciliation($day, $day))->toBe([
        ['date' => '2026-11-26', 'our_generations' => 1, 'provider_generations' => 0, 'our_cost_nano' => 7, 'provider_cost_nano' => 0, 'difference_nano' => 7],
    ]);
});

it('sums a list of rows into totals', function () {
    expect($this->report->totals([
        ['generations' => 1, 'price_nano' => 125, 'cost_nano' => 100, 'profit_nano' => 25],
        ['generations' => 2, 'price_nano' => 10, 'cost_nano' => 20, 'profit_nano' => -10],
    ]))->toBe(['generations' => 3, 'price_nano' => 135, 'cost_nano' => 120, 'profit_nano' => 15]);
});

it('lets provider errors reach the caller instead of showing a wrong match', function () {
    Http::fake(['pf.test/v1/usage*' => Http::response('', 502)]);

    $day = CarbonImmutable::parse('2026-11-26');
    $this->report->reconciliation($day, $day);
})->throws(ProviderUnavailable::class);

it('counts the generations still open for the current key', function () {
    $fingerprint = app(PixelforgeClient::class)->apiKeyFingerprint();
    Generation::factory()->for($this->ana)->create(['status' => GenerationStatus::Processing, 'api_key_fingerprint' => $fingerprint]);
    Generation::factory()->for($this->ana)->create(['status' => GenerationStatus::Queued, 'api_key_fingerprint' => $fingerprint]);
    Generation::factory()->for($this->ana)->create(['status' => GenerationStatus::Processing, 'api_key_fingerprint' => 'another-key-0000']);
    Generation::factory()->for($this->ana)->create(['status' => GenerationStatus::Pending]);
    // Sent once with no clear answer: it may exist (and be billed) at the provider.
    Generation::factory()->for($this->ana)->create(['status' => GenerationStatus::Pending, 'ambiguous' => true, 'api_key_fingerprint' => $fingerprint]);

    expect($this->report->openAtProvider())->toBe(3);
});
