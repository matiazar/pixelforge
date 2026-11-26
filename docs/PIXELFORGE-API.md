# Pixelforge API: integration notes

Español: [API-PIXELFORGE.md](API-PIXELFORGE.md)

Source: https://pixelforge.promptedit.dev/docs (read 2026-11-26). Summary of what the app uses.

## Auth and limits

- `Authorization: Bearer <key>`. 401 `invalid_api_key`
- 60 req/min, 3 generations queued+processing, 500 generations per UTC day. Per account (all keys)
- Headers: `Retry-After` (s), `X-RateLimit-Limit`, `X-RateLimit-Remaining`

## POST /v1/generations

- Body: prompt (max 4000), size (1024x1024 | 1536x1024 | 1024x1536), quality (low | medium | high), reference_images (max 4, http/https), metadata (max 20 string keys, values <= 500)
- `Idempotency-Key` (<= 255): same key + same parameters -> same generation, 202 with `Idempotent-Replayed: true`, no cost and no quota. Different parameters -> 409 `idempotency_key_reused`. Keys never expire
- 202: generation object with status `queued`, fixed `rates`, `metadata`

## Generation object

id (gen_...), object, status (queued -> processing -> succeeded | failed), prompt, size, quality, reference_images, metadata, image_url, image_url_expires_at (24 h after completion), usage (input_text_tokens, input_image_tokens, output_tokens, reasoning_tokens, total_tokens), rates, error {code, message} (`generation_failed`, `internal_error`), created_at, started_at, completed_at

## List and others

- GET /v1/generations/{id}; 404 `not_found` if it belongs to another account
- GET /v1/generations?limit=1..100&status=&cursor= -> {data, has_more, next_cursor}, newest first
- GET /v1/usage?start_date&end_date (UTC, inclusive, max 92 days) -> data[] per day with cost_usd (9 decimals), total. Per API key
- GET /v1/pricing without auth

## Errors

`{"error": {"type", "code", "message", "param", "errors"}}`

| code                       | HTTP | Note                           |
| -------------------------- | ---- | ------------------------------ |
| validation_failed          | 422  |                                |
| content_policy_violation   | 400  | nothing created                |
| not_found                  | 404  |                                |
| idempotency_key_reused     | 409  |                                |
| rate_limit_exceeded        | 429  | Retry-After                    |
| concurrency_limit_exceeded | 429  | Retry-After 5                  |
| daily_limit_exceeded       | 429  | no Retry-After                 |
| overloaded                 | 503  | Retry-After 2, nothing created |

500/502/504 are not documented: treated as ambiguous.

## Webhooks

- Events: generation.processing, generation.succeeded, generation.failed, ping
- Headers: Pixelforge-Event-Id, Pixelforge-Event-Type, Pixelforge-Delivery-Attempt, Pixelforge-Signature `t=<unix>,v1=<hex>`
- Signature: hex HMAC-SHA256 of `<t>.<raw body>` with the secret (whsec_...). 300 s tolerance
- Payload: {id, object: "event", type, created_at, data: {object: <generation>}}
- 2xx within 10 s = delivered. Otherwise retries at 10s, 30s, 2m, 10m, 15m (6 attempts). No redirects. At least once, with duplicates and out of order

## Magic words (prompt)

[blocked] 400, [overloaded] 503, [fail] fails while processing, [succeed] never fails, [slow] 60-90 s, [duplicate] every webhook twice. Without magic words: random failures and 503s, random duplicate webhooks
