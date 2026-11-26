<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\LedgerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

const WEBHOOK_SECRET = 'whsec_test';

beforeEach(function () {
    Queue::fake();
    config(['services.pixelforge.webhook_secret' => WEBHOOK_SECRET]);
    $this->user = User::factory()->create();
    app(LedgerService::class)->topUp($this->user, 1_000_000_000);
    $this->generation = Generation::factory()->for($this->user)->create([
        'status' => GenerationStatus::Queued,
        'provider_id' => 'gen_abc',
        'estimate_max_nano' => 467_343_750,
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
    ]);
    app(LedgerService::class)->hold($this->generation);
});

function webhookEvent(string $type, array $object, string $eventId = 'evt_1'): array
{
    return ['id' => $eventId, 'object' => 'event', 'type' => $type, 'created_at' => now()->toIso8601ZuluString(), 'data' => ['object' => $object]];
}

function generationObject(Generation $generation, string $status, array $overrides = []): array
{
    $object = [
        'id' => $generation->provider_id ?? 'gen_abc',
        'object' => 'generation',
        'status' => $status,
        'metadata' => ['local_id' => $generation->id],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'usage' => null,
        'error' => null,
        'image_url' => null,
        'image_url_expires_at' => null,
        'created_at' => now()->toIso8601ZuluString(),
        'completed_at' => null,
    ];

    if ($status === 'succeeded') {
        $object['usage'] = ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411];
        $object['image_url'] = 'https://img.test/a.png';
        $object['completed_at'] = now()->toIso8601ZuluString();
    }

    return array_replace($object, $overrides);
}

function sendWebhook(array $event, ?int $timestamp = null, ?string $secret = null, ?string $signatureHeader = null)
{
    $body = json_encode($event);
    $t = $timestamp ?? time();
    $signature = hash_hmac('sha256', "{$t}.{$body}", $secret ?? WEBHOOK_SECRET);

    return test()->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signatureHeader ?? "t={$t},v1={$signature}",
        'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
        'HTTP_PIXELFORGE_EVENT_TYPE' => $event['type'],
        'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
    ], $body);
}

it('applies a signed success event and charges once', function () {
    sendWebhook(webhookEvent('generation.succeeded', generationObject($this->generation, 'succeeded')))->assertNoContent();

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($this->user->fresh()->balance_nano)->toBe(1_000_000_000 - 381_887_500)
        ->and(WebhookEvent::first()->generation_id)->toBe($this->generation->id);
});

it('rejects a bad signature without touching anything', function () {
    sendWebhook(webhookEvent('generation.succeeded', generationObject($this->generation, 'succeeded')), secret: 'wrong')->assertStatus(400);

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Queued)
        ->and(WebhookEvent::count())->toBe(0);
});

it('rejects a timestamp outside the 300 second tolerance', function (int $offset) {
    sendWebhook(webhookEvent('ping', []), timestamp: time() + $offset)->assertStatus(400);
})->with([-310, 310]);

it('rejects a missing or malformed signature header', function (string $header) {
    sendWebhook(webhookEvent('ping', []), signatureHeader: $header)->assertStatus(400);
})->with(['', 'garbage', 't=123', 'v1=abc']);

it('ignores a duplicated event', function () {
    $event = webhookEvent('generation.succeeded', generationObject($this->generation, 'succeeded'));

    sendWebhook($event)->assertNoContent();
    sendWebhook($event)->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and(LedgerEntry::where('type', 'charge')->count())->toBe(1);
});

it('does not move back on out of order events', function () {
    sendWebhook(webhookEvent('generation.succeeded', generationObject($this->generation, 'succeeded'), 'evt_2'))->assertNoContent();
    sendWebhook(webhookEvent('generation.processing', generationObject($this->generation, 'processing'), 'evt_1'))->assertNoContent();

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('finds the generation by local_id when the webhook beats the POST response', function () {
    $pending = Generation::factory()->for($this->user)->create(['status' => GenerationStatus::Pending, 'ambiguous' => true]);
    app(LedgerService::class)->hold($pending);

    sendWebhook(webhookEvent('generation.processing', generationObject($pending, 'processing', ['id' => 'gen_new'])))->assertNoContent();

    $fresh = $pending->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Processing)
        ->and($fresh->provider_id)->toBe('gen_new')
        ->and($fresh->ambiguous)->toBeFalse();
});

it('records a ping and answers 204', function () {
    sendWebhook(webhookEvent('ping', []))->assertNoContent();

    expect(WebhookEvent::first()->type)->toBe('ping');
});

it('records an event for an unknown generation and answers 204', function () {
    sendWebhook(webhookEvent('generation.succeeded', ['id' => 'gen_unknown', 'status' => 'succeeded', 'metadata' => []]))->assertNoContent();

    expect(WebhookEvent::first()->generation_id)->toBeNull();
});

it('answers 5xx and records nothing when applying fails, so the provider retries', function () {
    $broken = generationObject($this->generation, 'succeeded', ['usage' => ['output_tokens' => 1]]);

    sendWebhook(webhookEvent('generation.succeeded', $broken))->assertStatus(500);

    expect(WebhookEvent::count())->toBe(0)
        ->and($this->generation->fresh()->status)->toBe(GenerationStatus::Queued);
});

it('rejects a body that is not a valid event', function () {
    $t = time();
    $body = 'not json';
    $signature = hash_hmac('sha256', "{$t}.{$body}", WEBHOOK_SECRET);

    $this->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$t},v1={$signature}",
    ], $body)->assertStatus(400);
});

it('accepts the delivery when any of several v1 signatures matches', function () {
    $event = webhookEvent('ping', []);
    $t = time();
    $good = hash_hmac('sha256', "{$t}.".json_encode($event), WEBHOOK_SECRET);

    sendWebhook($event, signatureHeader: "t={$t},v1={$good},v1=deadbeef")->assertNoContent();
});

it('logs a warning when a signature is rejected', function () {
    Log::spy();

    sendWebhook(webhookEvent('ping', []), secret: 'wrong')->assertStatus(400);

    Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'signature'))->once();
});

it('does not start a session for webhook deliveries', function () {
    sendWebhook(webhookEvent('ping', []))->assertNoContent()->assertCookieMissing(config('session.cookie'));
});
