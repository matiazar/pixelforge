<?php

namespace App\Services;

use App\Exceptions\InvalidMarkupSetting;
use App\Exceptions\UnsupportedRate;
use App\Models\Setting;
use App\Support\Money;
use App\Support\PriceEstimate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Estimates and final costs, all in integer nano-USD.
 *
 * Provider rates are USD per 1M tokens (decimal strings). One token at "5.00"
 * costs 5000 nano-USD; a rate that does not give a whole nano per token is rejected.
 */
class PricingService
{
    private const TOKENS_PER_RATE_UNIT = 1_000_000;

    private const PPM = 1_000_000;

    private const FALLBACK_CACHE_SECONDS = 60;

    public const MAX_REFERENCES = 4;

    public const SIZES = ['1024x1024', '1536x1024', '1024x1536'];

    public const QUALITIES = ['low', 'medium', 'high'];

    public const RATE_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * Pricing table (rates + token rules): live GET /v1/pricing cached 5 min,
     * config fallback when the provider is unreachable or answers garbage.
     *
     * @return array{rates: array<string, string>, tokens: array<string, mixed>}
     */
    public function currentTable(): array
    {
        /** @var array{rates: array<string, string>, tokens: array<string, mixed>}|null $cached Only this method writes the key, after validating. */
        $cached = Cache::get('pixelforge:pricing');

        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = Http::baseUrl((string) config('services.pixelforge.base_url'))
                ->timeout((int) config('services.pixelforge.timeout'))
                ->acceptJson()
                ->get('/v1/pricing')
                ->throw();

            $table = [
                'rates' => $response->json('rates'),
                'tokens' => $response->json('tokens'),
            ];
            $this->assertValidTable($table);
        } catch (Throwable $e) {
            // Fallback is a handled outcome: the estimate stays available with the documented rates.
            // Cached briefly so an outage costs one provider call per window, not one per estimate.
            Log::warning('Pixelforge pricing unavailable, using fallback table', ['reason' => $e->getMessage()]);
            $fallback = config('pixelforge.fallback_pricing');
            Cache::put('pixelforge:pricing', $fallback, self::FALLBACK_CACHE_SECONDS);

            return $fallback;
        }

        Cache::put('pixelforge:pricing', $table, (int) config('pixelforge.pricing_cache_seconds'));

        return $table;
    }

    /**
     * @param  array{rates: array<string, string>, tokens: array<string, mixed>}  $table
     */
    public function estimate(string $prompt, string $size, string $quality, int $referenceCount, array $table, int $markupPercent): PriceEstimate
    {
        if ($referenceCount < 0 || $referenceCount > self::MAX_REFERENCES) {
            throw new InvalidArgumentException('Reference count must be 0..'.self::MAX_REFERENCES.", got [{$referenceCount}]");
        }

        $tokens = $table['tokens'];
        $perToken = $this->nanoPerToken($table['rates']);

        // Assumption: the provider counts Unicode code points ("characters, not bytes" in its docs).

        $textTokens = $this->ceilDiv(mb_strlen($prompt, 'UTF-8'), (int) $tokens['characters_per_text_token']);
        $variancePpm = $this->ppm((string) $tokens['text_token_variance']);
        $textMin = intdiv($textTokens * (self::PPM - $variancePpm), self::PPM);
        $textMax = $this->ceilDiv($textTokens * (self::PPM + $variancePpm), self::PPM);

        $imageTokens = $referenceCount * (int) $tokens['per_reference_image'];

        $output = $tokens['output'][$quality][$size]
            ?? throw new InvalidArgumentException("Unknown quality/size [{$quality}/{$size}]");
        $reasoning = $tokens['reasoning'][$quality]
            ?? throw new InvalidArgumentException("Unknown quality [{$quality}]");

        $fixed = $imageTokens * $perToken['input_image_tokens'] + (int) $output * $perToken['output_tokens'];

        $costMin = $fixed + $textMin * $perToken['input_text_tokens'] + (int) $reasoning['min'] * $perToken['reasoning_tokens'];
        $costMax = $fixed + $textMax * $perToken['input_text_tokens'] + (int) $reasoning['max'] * $perToken['reasoning_tokens'];

        return new PriceEstimate(
            costMin: $costMin,
            costMax: $costMax,
            priceMin: $this->priceFor($costMin, $markupPercent),
            priceMax: $this->priceFor($costMax, $markupPercent),
        );
    }

    /**
     * Final provider cost from a finished generation's usage and the rates fixed at its creation.
     *
     * @param  array<string, int>  $usage
     * @param  array<string, string>  $rates
     */
    public function costFromUsage(array $usage, array $rates): int
    {
        $perToken = $this->nanoPerToken($rates);
        $cost = 0;

        foreach (self::RATE_TYPES as $type) {
            $tokens = $usage[$type] ?? null;

            if (! is_int($tokens) || $tokens < 0) {
                throw new InvalidArgumentException("Usage is missing a valid [{$type}] count");
            }

            $cost += $tokens * $perToken[$type];
        }

        return $cost;
    }

    /**
     * What the user pays: provider cost + markup, rounded up to the nano.
     * The markup is the one stored on the generation, never read here.
     */
    public function priceFor(int $costNano, int $markupPercent): int
    {
        return Money::withMarkup($costNano, $markupPercent);
    }

    /**
     * Markup in force now (admin setting). Read once per generation and stored
     * on it. A stored value that is not a whole percent 0..100 is refused
     * instead of cast: (int) '' would silently charge cost only.
     */
    public function markupPercent(): int
    {
        $stored = Setting::get('markup_percent');

        if (! ctype_digit($stored) || (int) $stored > 100) {
            throw InvalidMarkupSetting::for($stored);
        }

        return (int) $stored;
    }

    /**
     * @param  array<string, string>  $rates
     * @return array<string, int>
     */
    private function nanoPerToken(array $rates): array
    {
        $perToken = [];

        foreach (self::RATE_TYPES as $type) {
            $rate = (string) ($rates[$type] ?? throw new InvalidArgumentException("Missing rate [{$type}]"));
            $nanoPerUnit = Money::fromDecimalString($rate);

            if ($nanoPerUnit < 0 || $nanoPerUnit % self::TOKENS_PER_RATE_UNIT !== 0) {
                throw UnsupportedRate::for($type, $rate);
            }

            $perToken[$type] = intdiv($nanoPerUnit, self::TOKENS_PER_RATE_UNIT);
        }

        return $perToken;
    }

    /**
     * "0.15" -> 150000 parts per million, without floats.
     */
    private function ppm(string $fraction): int
    {
        if (preg_match('/^0(?:\.(\d{1,6}))?$/', $fraction, $m) !== 1) {
            throw new InvalidArgumentException("Unsupported variance [{$fraction}]");
        }

        return (int) str_pad($m[1] ?? '', 6, '0');
    }

    private function ceilDiv(int $numerator, int $divisor): int
    {
        return intdiv($numerator, $divisor) + ($numerator % $divisor > 0 ? 1 : 0);
    }

    /**
     * @param  array<string, mixed>  $table
     */
    private function assertValidTable(array $table): void
    {
        $this->nanoPerToken(is_array($table['rates']) ? $table['rates'] : []);

        $tokens = $table['tokens'];

        if (! is_array($tokens) || ! isset($tokens['characters_per_text_token'], $tokens['text_token_variance'], $tokens['per_reference_image'], $tokens['output'], $tokens['reasoning'])) {
            throw new InvalidArgumentException('Pricing response is missing token rules');
        }

        $this->ppm((string) $tokens['text_token_variance']);

        if (! is_int($tokens['characters_per_text_token']) || $tokens['characters_per_text_token'] < 1 || ! is_int($tokens['per_reference_image'])) {
            throw new InvalidArgumentException('Pricing response has invalid text or image token rules');
        }

        foreach (self::QUALITIES as $quality) {
            foreach (self::SIZES as $size) {
                if (! is_int($tokens['output'][$quality][$size] ?? null)) {
                    throw new InvalidArgumentException("Pricing response is missing output tokens for [{$quality}/{$size}]");
                }
            }

            $reasoning = $tokens['reasoning'][$quality] ?? null;

            if (! is_int($reasoning['min'] ?? null) || ! is_int($reasoning['max'] ?? null)) {
                throw new InvalidArgumentException("Pricing response is missing reasoning range for [{$quality}]");
            }
        }
    }
}
