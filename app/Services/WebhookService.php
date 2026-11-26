<?php

namespace App\Services;

use App\Exceptions\InvalidWebhookPayload;
use App\Exceptions\InvalidWebhookSignature;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Pixelforge webhooks: verify, deduplicate and apply in one transaction, so a
 * 2xx means "applied". Any failure rolls back the event row too and the
 * provider retries.
 */
class WebhookService
{
    public const TOLERANCE_SECONDS = 300;

    public function __construct(private GenerationStateService $state) {}

    public function receive(string $rawBody, ?string $signatureHeader, ?string $deliveryAttempt = null): void
    {
        $this->verify($rawBody, $signatureHeader);
        $event = $this->decode($rawBody);

        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : null;
        $isGenerationEvent = str_starts_with($event['type'], 'generation.') && $object !== null;
        $generation = $isGenerationEvent ? $this->findGeneration($object) : null;

        DB::transaction(function () use ($event, $object, $generation, $isGenerationEvent, $deliveryAttempt) {
            $inserted = WebhookEvent::query()->insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'generation_id' => $generation?->id,
                'payload' => json_encode($event, JSON_THROW_ON_ERROR),
                'delivery_attempt' => ctype_digit((string) $deliveryAttempt) ? (int) $deliveryAttempt : null,
                'received_at' => now(),
            ]);

            if ($inserted === 0) {
                return; // duplicate delivery: already applied
            }

            if (! $isGenerationEvent) {
                if ($event['type'] !== 'ping') {
                    Log::info('Ignoring unhandled webhook type', ['type' => $event['type'], 'event' => $event['id']]);
                }

                return;
            }

            if ($generation === null) {
                Log::warning('Webhook for an unknown generation', ['event' => $event['id'], 'provider_id' => $object['id'] ?? null]);

                return;
            }

            /** @var array<string, mixed> $object */
            $this->state->apply($generation, $object);
        });
    }

    private function verify(string $rawBody, ?string $header): void
    {
        $secret = (string) config('services.pixelforge.webhook_secret');

        if ($secret === '' || $header === null) {
            throw new InvalidWebhookSignature('Missing signature or secret');
        }

        $timestamp = '';
        $signatures = []; // several v1 values may come during a secret rotation
        foreach (explode(',', $header) as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if (! ctype_digit($timestamp) || $signatures === []) {
            throw new InvalidWebhookSignature('Malformed signature header');
        }

        // Each retry is signed with a fresh timestamp, so a tight window is safe.
        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            throw new InvalidWebhookSignature('Signature timestamp outside tolerance');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        $matches = array_filter($signatures, fn (string $signature) => hash_equals($expected, $signature));

        if ($matches === []) {
            throw new InvalidWebhookSignature('Signature mismatch');
        }
    }

    /**
     * @return array{id: string, type: string, data?: array<string, mixed>}
     */
    private function decode(string $rawBody): array
    {
        try {
            $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidWebhookPayload('Webhook body is not JSON', previous: $e);
        }

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            throw new InvalidWebhookPayload('Webhook event without id or type');
        }

        /** @var array{id: string, type: string, data?: array<string, mixed>} $event */
        return $event;
    }

    /**
     * By provider id, or by metadata.local_id when the webhook beats the POST response.
     *
     * @param  array<string, mixed>  $object
     */
    private function findGeneration(array $object): ?Generation
    {
        if (is_string($object['id'] ?? null)) {
            $byProvider = Generation::where('provider_id', $object['id'])->first();

            if ($byProvider !== null) {
                return $byProvider;
            }
        }

        $localId = $object['metadata']['local_id'] ?? null;

        return is_string($localId) && strlen($localId) === 26
            ? Generation::find($localId)
            : null;
    }
}
