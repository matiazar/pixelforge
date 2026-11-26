<?php

use App\Support\Money;

it('parses exact decimal strings into nano-USD', function (string $input, int $expected) {
    expect(Money::fromDecimalString($input))->toBe($expected);
})->with([
    ['0.373875000', 373_875_000],
    ['5.00', 5_000_000_000],
    ['12.5', 12_500_000_000],
    ['0.000000001', 1],
    ['3', 3_000_000_000],
    ['-0.50', -500_000_000],
]);

it('rejects amounts that are not exact or have more than 9 decimals', function (string $input) {
    Money::fromDecimalString($input);
})->with(['0.0000000001', '1e-3', 'abc', '', '1,50', '99999999999.5'])->throws(InvalidArgumentException::class);

it('formats nano-USD with 2 to 9 decimals and no rounding', function (int $nano, string $expected) {
    expect(Money::format($nano))->toBe($expected);
})->with([
    [467_343_750, '0.46734375'],
    [373_875_000, '0.373875'],
    [5_000_000_000, '5.00'],
    [1, '0.000000001'],
    [0, '0.00'],
    [-500_000_000, '-0.50'],
]);

it('applies the markup rounding up to the next nano', function () {
    expect(Money::withMarkup(373_875_000, 25))->toBe(467_343_750)
        ->and(Money::withMarkup(1, 25))->toBe(2)
        ->and(Money::withMarkup(4, 25))->toBe(5)
        ->and(Money::withMarkup(0, 25))->toBe(0);
});
