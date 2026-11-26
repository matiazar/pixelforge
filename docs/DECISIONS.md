# Decisions: Pixelforge Studio

Español: [DECISIONES.md](DECISIONES.md)

Architecture decision log. Append-only: a decision that changes gets a new entry that replaces it; old entries are not edited. A new entry goes into both files.

## DEC-001: Laravel 13 + Vue 3 + Inertia stack (official starter kit)

Date: 2026-11-26
Status: In force
Origin: technical spec

Context: the assignment requires Laravel + Vue; auth is needed and delivery is today.
Decision: the official Vue starter kit (Inertia, TypeScript, Tailwind, shadcn-vue, Fortify) on Laravel 13. Tests with Pest.
Rejected alternative: a Laravel API plus a separate Vue SPA.
Why: the kit brings auth and routing solved; a single deploy.
Consequences: the frontend is coupled to Inertia; no public API.

## DEC-002: PostgreSQL

Date: 2026-11-26
Status: In force
Origin: technical spec

Context: there is money, concurrency (dispatcher, webhooks) and reports by time zone.
Decision: PostgreSQL in dev, testing (`pixelforge_testing`) and production.
Rejected alternative: SQLite / MySQL.
Why: reliable transactions and row locks, grouping by time zone with `AT TIME ZONE`.
Consequences: tests need a local Postgres.

## DEC-003: Money as integer nano-USD

Date: 2026-11-26
Status: In force
Origin: technical spec

Context: the provider reports costs with 9 decimals; the price is cost + 25%.
Decision: BIGINT in nano-USD (1 USD = 1_000_000_000). No floats anywhere; the frontend receives formatted strings.
Rejected alternative: cents, or DECIMAL with floats in PHP.
Why: exact, and matches the 9 decimals of usage.
Consequences: every conversion goes through `App\Support\Money`; rates that do not give a whole number throw an exception.

## DEC-004: Own queue and async send to the provider

Date: 2026-11-26
Status: In force
Origin: technical spec

Context: the provider accepts at most 3 generations in flight for the whole account and has rate limits.
Decision: the web request only creates the generation and holds the balance; a dispatcher (locked job) does the POST.
Rejected alternative: the POST inside the web request.
Why: one path for limits, retries and the queue the user sees.
Consequences: needs a worker (Supervisor) and the scheduler (cron) in production.
Adversarial doubt: accepted a way out for ambiguous attempts and a single 50/min limiter.

## DEC-005: Append-only ledger + cached balance

Date: 2026-11-26
Status: In force
Origin: technical spec

Context: we must hold, release and charge without charging twice.
Decision: `ledger_entries` (topup, hold, release, charge) with a unique (generation_id, type), and `users.balance_nano` as a cache.
Rejected alternative: a balance column only.
Why: auditable; the unique index makes a double charge impossible.
Consequences: every balance write goes through `LedgerService` inside a transaction.
Adversarial doubt: accepted charging above the hold (the balance can go negative, which blocks new generations).

## DEC-006: Persist before calling + Idempotency-Key

Date: 2026-11-26
Status: In force
Origin: technical spec

Context: the webhook can arrive before the POST response; a cut can leave the result uncertain.
Decision: the generation (ULID = Idempotency-Key = metadata.local_id) is stored before the POST, marked `ambiguous` before calling.
Rejected alternative: create the record when the 202 arrives.
Why: no webhook is ever orphaned and a cut never loses charges.
Consequences: ambiguous attempts need reconciliation by metadata.local_id.
Adversarial doubt: accepted the attempt mark before the POST and a reversible local failure.

## DEC-007: A single status service

Date: 2026-11-26
Status: In force
Origin: technical spec

Context: status arrives by POST, webhook, polling and reconciliation, with duplicates and out of order.
Decision: `GenerationStateService` with a row lock, order queued < processing < final, provider final states immutable.
Rejected alternative: separate logic per source.
Why: the same rules for duplicates and ordering on every path.
Consequences: no other code changes `status` directly.

## DEC-008: Webhook processed in the request

Date: 2026-11-26
Status: In force
Origin: technical spec

Context: the provider retries if it does not get a 2xx.
Decision: verify the signature, insert event_id and apply the status in the same transaction; 2xx only if applied.
Rejected alternative: queue the webhook.
Why: it is fast and the 2xx means applied; a failure returns 5xx and the provider retries.
Consequences: the endpoint must be light and without CSRF.

## DEC-009: Database queue driver (no Redis)

Date: 2026-11-26
Status: In force for the queue (the cache part is replaced by DEC-012)
Origin: technical spec

Context: small volume, delivery today, own VPS.
Decision: `QUEUE_CONNECTION=database` + scheduler.
Rejected alternative: Redis.
Why: less infrastructure.
Consequences: cache locks through database/file; review if volume grows.

## DEC-010: VPS with Apache

Date: 2026-11-26
Status: In force
Origin: owner's request

Context: the owner's VPS already runs Apache.
Decision: Apache (vhost + mod_rewrite) + PHP-FPM, Supervisor for the worker, cron for `schedule:run`, Let's Encrypt.
Rejected alternative: Nginx (what the spec draft proposed).
Why: it is the server that already exists on the VPS.
Consequences: the deploy is documented for Apache; the webhook needs the raw body intact (no Apache filters that rewrite it).

## DEC-011: Markup editable by the admin, stored on each generation

Date: 2026-11-26
Status: In force (replaces "fixed 25% in config, not editable" from spec section 2)
Origin: owner's request

Context: the assignment says "cost plus a 25% markup" but does not fix it as non-editable. The admin wants to adjust it.
Decision: global setting `markup_percent` (integer 0..100, default 25). Read once when the generation is created, used for the estimate and the hold, and stored in `generations.markup_percent` (NOT NULL, no default). The final charge uses the generation's value, never the current one. An invalid stored value throws an exception instead of becoming 0.
Rejected alternative: charge with the markup in force at settlement (the charge might not match what was held).
Why: same as the provider rates, which are fixed when the generation is created.
Consequences: earlier rows stay at 25 (they were charged that way). Known risk: the live estimate on the form can be from before a markup change; the hold and the charge use the markup read on submit. The report sums real price/cost but does not show which markup applied per row.

## DEC-012: Redis cache; settings cached until edited

Date: 2026-11-26
Status: In force (replaces the cache part of DEC-009; the queue stays on database)
Origin: owner's request

Context: settings are read on almost every request (registration, Inertia, daily limit, markup).
Decision: `CACHE_STORE=redis` (phpredis) for the whole cache: pricing, the shared Pixelforge rate limiter, unique job locks and settings. Settings: all rows under one key with no expiry, versioned (`settings:<version>`). On save (`Setting::putMany`, in a transaction) the version changes after the commit, so a slow reader that read the old rows can only write them under the previous version, which nobody reads any more.
Rejected alternative: only settings in Redis and the rest on database.
Why: owner's request; less load on the database.
Consequences: the VPS needs Redis and the phpredis extension. If Redis is down, every request that reads settings fails. A change made directly in the settings table is not seen until a save from Settings or deleting the `settings:version` key. Do not use `cache:clear` if Redis is shared: it empties the whole cache database. Tests: array store (phpunit.xml).
