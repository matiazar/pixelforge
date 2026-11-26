<?php

namespace App\Support;

/**
 * Estimated provider cost and user price range, in nano-USD.
 */
final readonly class PriceEstimate
{
    public function __construct(
        public int $costMin,
        public int $costMax,
        public int $priceMin,
        public int $priceMax,
    ) {}

    /**
     * @return array{min: string, max: string}
     */
    public function formattedPrice(): array
    {
        return [
            'min' => Money::format($this->priceMin),
            'max' => Money::format($this->priceMax),
        ];
    }
}
