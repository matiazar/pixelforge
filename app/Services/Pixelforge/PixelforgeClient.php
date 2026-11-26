<?php

namespace App\Services\Pixelforge;

use App\Exceptions\ApiThrottled;
use App\Exceptions\ProviderRejected;
use App\Exceptions\ProviderUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Thin HTTP client for the authenticated Pixelforge endpoints.
 *
 * Every call goes through one limiter (50 per minute, below the provider's 60)
 * and honours the provider's Retry-After / X-RateLimit-Remaining. Outcomes:
 * - array: success
 * - ProviderRejected: clear answer from the provider (4xx, 503 overloaded)
 * - ProviderUnavailable: ambiguous (timeout, connection drop, other 5xx)
 * - ApiThrottled: not sent at all
 */
class PixelforgeClient
{
    private const LIMITER_KEY = 'pixelforge-api';

    private const PAUSE_KEY = 'pixelforge:paused_until';

    private const PER_MINUTE = 50;

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createGeneration(string $idempotencyKey, array $body): array
    {
        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('/v1/generations', $body));
    }

    /**
     * @return array<string, mixed>
     */
    public function getGeneration(string $providerId): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/v1/generations/'.rawurlencode($providerId)));
    }

    /**
     * @return array{data: list<array<string, mixed>>, has_more: bool, next_cursor: string|null}
     */
    public function listGenerations(?string $cursor = null, int $limit = 100): array
    {
        $query = array_filter(['limit' => $limit, 'cursor' => $cursor], fn ($v) => $v !== null);

        /** @var array{data: list<array<string, mixed>>, has_more: bool, next_cursor: string|null} */
        return $this->send(fn (PendingRequest $http) => $http->get('/v1/generations', $query));
    }

    /**
     * @return array<string, mixed>
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/v1/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /**
     * Identifies which key created a generation without storing the key.
     */
    public function apiKeyFingerprint(): string
    {
        return substr(hash('sha256', (string) config('services.pixelforge.api_key')), 0, 16);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        $this->guardBudget();

        try {
            $response = $call($this->http());
        } catch (ConnectionException $e) {
            throw new ProviderUnavailable('Pixelforge did not answer: '.$e->getMessage(), previous: $e);
        }

        $this->rememberProviderBudget($response);

        if ($response->successful()) {
            return (array) $response->json();
        }

        $code = $response->json('error.code');

        if ($response->clientError() || $code === 'overloaded') {
            $retryAfter = $this->retryAfter($response);

            if ($code === 'rate_limit_exceeded') {
                $this->pauseFor($retryAfter ?? 60);
            }

            throw new ProviderRejected(
                $response->status(),
                is_string($code) ? $code : 'http_'.$response->status(),
                (string) ($response->json('error.message') ?? $response->reason()),
                $retryAfter,
            );
        }

        throw new ProviderUnavailable("Pixelforge answered {$response->status()} without a documented error");
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('services.pixelforge.base_url'))
            ->withToken((string) config('services.pixelforge.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.pixelforge.timeout'))
            ->withoutRedirecting();
    }

    private function guardBudget(): void
    {
        $pausedUntil = (int) Cache::get(self::PAUSE_KEY, 0);

        if ($pausedUntil > time()) {
            throw new ApiThrottled($pausedUntil - time());
        }

        if (RateLimiter::tooManyAttempts(self::LIMITER_KEY, self::PER_MINUTE)) {
            throw new ApiThrottled(RateLimiter::availableIn(self::LIMITER_KEY));
        }

        RateLimiter::hit(self::LIMITER_KEY, 60);
    }

    private function rememberProviderBudget(Response $response): void
    {
        if ($response->header('X-RateLimit-Remaining') === '0') {
            $this->pauseFor($this->retryAfter($response) ?? 60);
        }
    }

    private function pauseFor(int $seconds): void
    {
        Cache::put(self::PAUSE_KEY, time() + $seconds, $seconds);
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');

        return ctype_digit($value) ? (int) $value : null;
    }
}
