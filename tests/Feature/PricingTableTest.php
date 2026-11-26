<?php

use App\Services\PricingService;
use Illuminate\Support\Facades\Http;

function livePricing(array $rates = []): array
{
    return [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => $rates + config('pixelforge.fallback_pricing.rates'),
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => config('pixelforge.fallback_pricing.tokens.output'),
            'reasoning' => config('pixelforge.fallback_pricing.tokens.reasoning'),
        ],
    ];
}

it('uses the live pricing table and caches it', function () {
    Http::fake(['*/v1/pricing' => Http::response(livePricing(['output_tokens' => '80.00']))]);
    $pricing = app(PricingService::class);

    $first = $pricing->currentTable();
    $second = $pricing->currentTable();

    expect($first['rates']['output_tokens'])->toBe('80.00')
        ->and($second)->toBe($first);
    Http::assertSentCount(1);
});

it('accepts the numeric variance the API returns', function () {
    Http::fake(['*/v1/pricing' => Http::response(livePricing())]);
    $pricing = app(PricingService::class);

    $estimate = $pricing->estimate(str_repeat('a', 400), '1536x1024', 'high', 2, $pricing->currentTable(), 25);

    expect($estimate->costMax)->toBe(373_875_000);
});

it('falls back to the config table when the provider fails', function () {
    Http::fake(['*/v1/pricing' => Http::response(['error' => 'down'], 503)]);

    expect(app(PricingService::class)->currentTable())->toBe(config('pixelforge.fallback_pricing'));
});

it('falls back when the provider returns rates we cannot represent', function () {
    Http::fake(['*/v1/pricing' => Http::response(livePricing(['output_tokens' => '0.0000001']))]);

    expect(app(PricingService::class)->currentTable())->toBe(config('pixelforge.fallback_pricing'));
});

it('caches the fallback so an outage does not hit the provider on every estimate', function () {
    Http::fake(['*/v1/pricing' => Http::response(['error' => 'down'], 503)]);
    $pricing = app(PricingService::class);

    $pricing->currentTable();
    $pricing->currentTable();

    Http::assertSentCount(1);
});

it('falls back when the live table is missing a quality', function () {
    $live = livePricing();
    unset($live['tokens']['output']['high']);
    Http::fake(['*/v1/pricing' => Http::response($live)]);

    expect(app(PricingService::class)->currentTable())->toBe(config('pixelforge.fallback_pricing'));
});
