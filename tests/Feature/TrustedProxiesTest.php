<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

afterEach(function () {
    TrustProxies::flushState();
    // The trusted list lives in a static on the Symfony request and would leak into the next test.
    Request::setTrustedProxies([], -1);
});

function bootWithTrustedProxies(?string $proxies): void
{
    // Start clean: the local .env may already trust proxies when the app boots.
    TrustProxies::flushState();
    config(['app.trusted_proxies' => $proxies]);
    (new AppServiceProvider(app()))->boot();
}

test('redirects keep https when the request comes through a trusted proxy', function () {
    bootWithTrustedProxies('*');

    $response = $this->actingAs(User::factory()->create())
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->post(route('logout'))
        ->assertRedirect();

    expect($response->headers->get('Location'))->toStartWith('https://');
});

test('forwarded headers are ignored when no proxy is trusted', function () {
    bootWithTrustedProxies(null);

    $response = $this->actingAs(User::factory()->create())
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https'])
        ->post(route('logout'))
        ->assertRedirect();

    expect($response->headers->get('Location'))->toStartWith('http://');
});

test('a forwarded host from a trusted proxy never changes generated links', function () {
    bootWithTrustedProxies('*');

    $response = $this->actingAs(User::factory()->create())
        ->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example'])
        ->post(route('logout'))
        ->assertRedirect();

    expect($response->headers->get('Location'))
        ->toStartWith('https://')
        ->not->toContain('evil.example');
});
