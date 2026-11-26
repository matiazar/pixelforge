<?php

use App\Exceptions\ApiThrottled;
use App\Exceptions\ProviderRejected;
use App\Exceptions\ProviderUnavailable;
use App\Services\Pixelforge\PixelforgeClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.pixelforge.base_url' => 'https://pf.test',
        'services.pixelforge.api_key' => 'pf_test_key',
    ]);
    $this->client = app(PixelforgeClient::class);
});

function providerGeneration(array $overrides = []): array
{
    return $overrides + [
        'id' => 'gen_01',
        'object' => 'generation',
        'status' => 'queued',
        'metadata' => ['local_id' => '01local'],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'usage' => null,
        'error' => null,
        'image_url' => null,
        'image_url_expires_at' => null,
        'created_at' => '2026-11-26T12:00:00Z',
    ];
}

function providerError(string $code, int $status, array $headers = []): PromiseInterface
{
    return Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => 'msg']], $status, $headers);
}

it('creates a generation with bearer auth and the idempotency key', function () {
    Http::fake(['pf.test/v1/generations' => Http::response(providerGeneration(), 202)]);

    $generation = $this->client->createGeneration('01local', ['prompt' => 'hi', 'metadata' => ['local_id' => '01local']]);

    expect($generation['id'])->toBe('gen_01');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->hasHeader('Authorization', 'Bearer pf_test_key')
        && $r->hasHeader('Idempotency-Key', '01local')
        && $r['prompt'] === 'hi');
});

it('turns a clear provider error into ProviderRejected with its code and Retry-After', function () {
    Http::fake(['pf.test/*' => providerError('concurrency_limit_exceeded', 429, ['Retry-After' => '5'])]);

    try {
        $this->client->createGeneration('k', ['prompt' => 'hi']);
        $this->fail('Expected ProviderRejected');
    } catch (ProviderRejected $e) {
        expect($e->errorCode)->toBe('concurrency_limit_exceeded')
            ->and($e->httpStatus)->toBe(429)
            ->and($e->retryAfter)->toBe(5);
    }
});

it('treats 503 overloaded as a clear rejection, not an ambiguous result', function () {
    Http::fake(['pf.test/*' => providerError('overloaded', 503, ['Retry-After' => '2'])]);

    $this->client->createGeneration('k', ['prompt' => 'hi']);
})->throws(ProviderRejected::class);

it('treats an undocumented 5xx as ambiguous', function (int $status) {
    Http::fake(['pf.test/*' => Http::response('oops', $status)]);

    $this->client->createGeneration('k', ['prompt' => 'hi']);
})->with([500, 502, 504])->throws(ProviderUnavailable::class);

it('treats a timeout or dropped connection as ambiguous', function () {
    Http::fake(['pf.test/*' => fn () => throw new ConnectionException('timed out')]);

    $this->client->createGeneration('k', ['prompt' => 'hi']);
})->throws(ProviderUnavailable::class);

it('stops calling once our own budget of 50 requests per minute is used', function () {
    Http::fake(['pf.test/*' => Http::response(providerGeneration())]);

    for ($i = 0; $i < 50; $i++) {
        $this->client->getGeneration('gen_01');
    }

    expect(fn () => $this->client->getGeneration('gen_01'))->toThrow(ApiThrottled::class);
    Http::assertSentCount(50);
});

it('pauses when the provider says no requests remain this minute', function () {
    Http::fake(['pf.test/*' => Http::response(providerGeneration(), 200, ['X-RateLimit-Remaining' => '0'])]);

    $this->client->getGeneration('gen_01');

    expect(fn () => $this->client->getGeneration('gen_01'))->toThrow(ApiThrottled::class);
    Http::assertSentCount(1);
});

it('pauses for the Retry-After of a per-minute rate limit', function () {
    Http::fake(['pf.test/*' => providerError('rate_limit_exceeded', 429, ['Retry-After' => '20'])]);

    expect(fn () => $this->client->getGeneration('gen_01'))->toThrow(ProviderRejected::class)
        ->and(fn () => $this->client->getGeneration('gen_01'))->toThrow(ApiThrottled::class);
});

it('lists generations with cursor and limit', function () {
    Http::fake(['pf.test/v1/generations*' => Http::response(['object' => 'list', 'data' => [providerGeneration()], 'has_more' => true, 'next_cursor' => 'c2'])]);

    $page = $this->client->listGenerations('c1', 100);

    expect($page['next_cursor'])->toBe('c2');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'cursor=c1') && str_contains($r->url(), 'limit=100'));
});

it('fingerprints the api key without exposing it', function () {
    $fingerprint = $this->client->apiKeyFingerprint();

    expect($fingerprint)->toHaveLength(16)
        ->and($fingerprint)->not->toContain('pf_test_key')
        ->and($fingerprint)->toBe(app(PixelforgeClient::class)->apiKeyFingerprint());
});
