<?php

use App\Exceptions\UnsupportedRate;
use App\Services\PricingService;

beforeEach(function () {
    $this->pricing = new PricingService;
    $this->table = config('pixelforge.fallback_pricing');
});

it('matches the documented maximum-cost case', function () {
    // high, 1536x1024, 400 characters, 2 references, max reasoning
    $estimate = $this->pricing->estimate(str_repeat('a', 400), '1536x1024', 'high', 2, $this->table);

    expect($estimate->costMax)->toBe(373_875_000)       // 0.373875
        ->and($estimate->priceMax)->toBe(467_343_750);  // 0.46734375
});

it('computes the minimum with floor(t x 0.85) and minimum reasoning', function () {
    $estimate = $this->pricing->estimate(str_repeat('a', 400), '1536x1024', 'high', 2, $this->table);

    // text 85 x 5000 + image 1530 x 10000 + output 6450 x 40000 + reasoning 1000 x 12500
    expect($estimate->costMin)->toBe(425_000 + 15_300_000 + 258_000_000 + 12_500_000)
        ->and($estimate->priceMin)->toBe(357_781_250);
});

it('counts Unicode characters, not bytes', function () {
    $emoji = $this->pricing->estimate(str_repeat('🎨', 8), '1024x1024', 'low', 0, $this->table);
    $ascii = $this->pricing->estimate(str_repeat('a', 8), '1024x1024', 'low', 0, $this->table);

    expect($emoji)->toEqual($ascii);
});

it('rounds partial text tokens up', function () {
    // 5 characters -> ceil(5/4) = 2 tokens -> max ceil(2 x 1.15) = 3, min floor(2 x 0.85) = 1
    $estimate = $this->pricing->estimate('abcde', '1024x1024', 'low', 0, $this->table);

    expect($estimate->costMax)->toBe(3 * 5000 + 280 * 40000 + 500 * 12500)
        ->and($estimate->costMin)->toBe(1 * 5000 + 280 * 40000);
});

it('computes the final cost from usage and the generation rates', function () {
    // Documented usage example: 12 text, 765 image, 6450 output, 3184 reasoning
    $cost = $this->pricing->costFromUsage([
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 3184,
    ], $this->table['rates']);

    expect($cost)->toBe(305_510_000);
});

it('matches the documented daily usage cost', function () {
    $cost = $this->pricing->costFromUsage([
        'input_text_tokens' => 41,
        'input_image_tokens' => 0,
        'output_tokens' => 3300,
        'reasoning_tokens' => 2950,
    ], $this->table['rates']);

    expect($cost)->toBe(169_080_000); // "0.169080000"
});

it('rejects a rate that is not a whole nano per token', function () {
    $rates = ['input_text_tokens' => '0.0000001'] + $this->table['rates'];

    $this->pricing->costFromUsage(['input_text_tokens' => 1], $rates);
})->throws(UnsupportedRate::class);

it('rejects an unknown size or quality', function () {
    $this->pricing->estimate('hi', '512x512', 'low', 0, $this->table);
})->throws(InvalidArgumentException::class);

it('rejects a reference count outside 0..4', function (int $count) {
    $this->pricing->estimate('hi', '1024x1024', 'low', $count, $this->table);
})->with([-1, 5])->throws(InvalidArgumentException::class);

it('refuses to bill a usage payload with a missing token type', function () {
    $this->pricing->costFromUsage([
        'input_text_tokens' => 12,
        'input_image_tokens' => 0,
        'output_tokens' => 6450,
    ], $this->table['rates']);
})->throws(InvalidArgumentException::class);
