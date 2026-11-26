# Pixelforge API: notas de integracion

Fuente: https://pixelforge.promptedit.dev/docs (leido 2026-11-26). Resumen de lo que usa la app.

## Auth y limites

- `Authorization: Bearer <key>`. 401 `invalid_api_key`
- 60 req/min, 3 generaciones queued+processing, 500 generaciones por dia UTC. Por cuenta (todas las keys)
- Headers: `Retry-After` (s), `X-RateLimit-Limit`, `X-RateLimit-Remaining`

## POST /v1/generations

- Body: prompt (max 4000), size (1024x1024 | 1536x1024 | 1024x1536), quality (low | medium | high), reference_images (max 4, http/https), metadata (max 20 claves string, valores <= 500)
- `Idempotency-Key` (<= 255): misma clave + mismos parametros -> misma generacion, 202 con `Idempotent-Replayed: true`, sin costo ni cupo. Parametros distintos -> 409 `idempotency_key_reused`. No vencen
- 202: objeto generation con status `queued`, `rates` fijadas, `metadata`

## Objeto generation

id (gen_...), object, status (queued -> processing -> succeeded | failed), prompt, size, quality, reference_images, metadata, image_url, image_url_expires_at (24 h despues de completar), usage (input_text_tokens, input_image_tokens, output_tokens, reasoning_tokens, total_tokens), rates, error {code, message} (`generation_failed`, `internal_error`), created_at, started_at, completed_at

## Listado y otros

- GET /v1/generations/{id}; 404 `not_found` si es de otra cuenta
- GET /v1/generations?limit=1..100&status=&cursor= -> {data, has_more, next_cursor}, mas nuevas primero
- GET /v1/usage?start_date&end_date (UTC, inclusivo, max 92 dias) -> data[] por dia con cost_usd (9 decimales), total. Por API key
- GET /v1/pricing sin auth

## Errores

`{"error": {"type", "code", "message", "param", "errors"}}`

| code                       | HTTP | Nota                       |
| -------------------------- | ---- | -------------------------- |
| validation_failed          | 422  |                            |
| content_policy_violation   | 400  | nada creado                |
| not_found                  | 404  |                            |
| idempotency_key_reused     | 409  |                            |
| rate_limit_exceeded        | 429  | Retry-After                |
| concurrency_limit_exceeded | 429  | Retry-After 5              |
| daily_limit_exceeded       | 429  | sin Retry-After            |
| overloaded                 | 503  | Retry-After 2, nada creado |

500/502/504 no documentados: se tratan como ambiguos.

## Webhooks

- Eventos: generation.processing, generation.succeeded, generation.failed, ping
- Headers: Pixelforge-Event-Id, Pixelforge-Event-Type, Pixelforge-Delivery-Attempt, Pixelforge-Signature `t=<unix>,v1=<hex>`
- Firma: hex HMAC-SHA256 de `<t>.<cuerpo crudo>` con el secreto (whsec_...). Tolerancia 300 s
- Payload: {id, object: "event", type, created_at, data: {object: <generation>}}
- 2xx en 10 s = entregado. Si no: reintentos a 10s, 30s, 2m, 10m, 15m (6 intentos). Sin redirects. Al menos una vez, con duplicados y desorden

## Palabras magicas (prompt)

[blocked] 400, [overloaded] 503, [fail] falla al procesar, [succeed] no falla, [slow] 60-90 s, [duplicate] cada webhook dos veces. Sin palabras: fallas y 503 al azar, webhooks duplicados al azar
