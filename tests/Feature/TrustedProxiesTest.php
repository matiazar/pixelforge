<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;

afterEach(function () {
    TrustProxies::flushState();
});

function bootWithTrustedProxies(?string $proxies): void
{
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
