# Technical spec: Pixelforge Studio

Español: [SPEC-TECNICO.md](SPEC-TECNICO.md)

Status: APPROVED (2026-11-26), updated after stage 8
Source: [relevamiento/QUESTIONS-pixelforge.md](relevamiento/QUESTIONS-pixelforge.md)
Assignment: https://pixelforge.promptedit.dev/assessment
API: https://pixelforge.promptedit.dev/docs (integration notes in [PIXELFORGE-API.md](PIXELFORGE-API.md))

## 1. Summary

Technical assessment for PromptEdit. A web app where a signed-in user generates images with the simulated Pixelforge API, sees the cost before submitting, pays the provider cost plus a markup (25% by default, set by the admin) from a fictitious balance, and follows the status live. An admin sees spend, cost and profit. Delivery: today. The assignment's priority: correctness before polish.

## 2. Scope (included)

- Sign up and sign in with email and password. English interface.
- Form: prompt (max 4000), size, quality, up to 4 reference URLs, estimate as a range.
- Own queue, with a notice to the user when the request is waiting for its turn.
- History with live status (queued, processing, succeeded, failed), estimated and final price, image.
- Retry button on failed generations.
- Signed webhooks plus backup polling.
- Balances, fictitious "Buy credits" button, blocked when the balance is not enough.
- Admin: report by day and by user, reconciliation against GET /v1/usage, global settings.
- Deploy on our own VPS, public GitHub repo, README, demo data.

## 3. Out of scope

Real payments, invoicing, downloading or keeping images permanently, cancelling generations, email verification and password recovery, 2FA, account deletion (it would destroy money records), other languages, mobile app.

## 4. Assumptions

Confirmed by the owner: estimate as a range; the user sees only their price; visible queue; open registration with a daily limit; manual retry; English; time zone as a global setting; hold of the maximum; examples loaded and room left to generate; test account; delivery today.

Assumed (to review):

1. Daily limit: 20 generations per user per day (UTC), editable in settings.
2. Global settings: time zone (default UTC), daily limit, registration open yes/no, markup (default 25%, stored on each generation; DEC-011).
3. Fictitious credit packs: 5, 10 and 20 USD. A new user starts at 0.
4. Demo and admin accounts created by the seeder; passwords from .env, never in the repo.
5. Amounts on screen in USD with up to 9 decimals, at least 2, never rounded.
6. VPS with Virtualmin + Apache, domain pixelforge.mazar.com.ar with HTTPS. The owner deploys, as the last stage.
7. PostgreSQL.
8. Error reports by email to ADMIN_EMAIL if there is SMTP; otherwise log only.
9. Learning mode during the build (APRENDIZAJE-pixelforge.md).

## 5. Stack and why

- Laravel 13 on PHP 8.4 + Vue 3 + Inertia, official Vue starter kit (TypeScript, Tailwind, shadcn-vue). The assignment requires it and the kit brings auth.
- PostgreSQL: transactions, row locks, grouping by time zone.
- Database queue driver + scheduler. Redis cache (DEC-012).
- Own VPS: Apache (vhost + mod_rewrite), PHP-FPM, Supervisor (worker), cron (schedule:run), Let's Encrypt.
- Pest.

## 6. Roles and permissions

| Role  | Can                                                                                     |
| ----- | --------------------------------------------------------------------------------------- |
| User  | Generate, retry their failed ones, see their history and balance, buy fictitious credit |
| Admin | Everything a User can, plus report, reconciliation, settings                            |

Policy per generation (a user never sees another user's). Admin through the is_admin column, set only by the seeder.

## 7. Data model

Money: BIGINT in nano-USD (1 USD = 1_000_000_000). No floats anywhere, not in the frontend either (the server sends formatted strings).

- users: is_admin, balance_nano (cache of the ledger)
- generations: ULID id (also the Idempotency-Key and metadata.local_id), user_id, form_token (unique), retry_of_id (unique, nullable), provider_id (unique, nullable), api_key_fingerprint, request_payload (json, resent identical), status, failure_source (provider | local), error_code, error_message, rates (json, from the provider), markup_percent, usage (json), estimate_min_nano, estimate_max_nano, cost_nano, price_nano, image_url, image_url_expires_at, attempts, ambiguous (bool), attempt_started_at, next_attempt_at, last_synced_at, provider_created_at, completed_at
- ledger_entries: user_id, generation_id (nullable), type (topup, hold, release, charge), signed amount_nano. Unique (generation_id, type). Append-only
- webhook_events: event_id (unique), type, generation_id (nullable), payload, delivery_attempt, received_at
- settings: key, value

## 8. Modules

**Pricing** (plain PHP, test-first on the documented cases)

- Estimate: text t = ceil(Unicode characters / 4), minimum floor(t x 0.85), maximum ceil(t x 1.15); 765 per reference image; output by table; reasoning min-max by quality. Price = cost x (100 + markup) / 100, with the markup stored on the generation (DEC-011).
- Estimate rates: GET /v1/pricing cached 5 min, fallback values in config.
- Final cost: usage x the rates of that generation. Decimal rates are converted from string to nano-USD per token; if it is not a whole number, an exception. If the markup does not give a whole nano, round up to the next nano.
- Control case: high, 1536x1024, 400 characters, 2 references: maximum cost 0.373875, maximum price (25%) 0.46734375.

**Create a generation**

- Transaction with the user row locked: checks the daily limit and balance >= maximum price, creates the generation, records the hold. form_token stops double submits.
- No free slot: message "Your request is in the queue".

**Dispatcher** (locked job; triggered on create, when one finishes, and by the scheduler)

- FIFO by next_attempt_at while fewer than 3 accepted ones have no final state. Ambiguous ones do not count and do not block the head.
- Before the POST it stores attempt_started_at and ambiguous = true (committed). It only goes back to false on a clear rejection with no earlier ambiguous attempts. A worker that dies leaves the mark.

| Response                    | Action                                                                    |
| --------------------------- | ------------------------------------------------------------------------- |
| 202                         | Apply the snapshot                                                        |
| 429 concurrency / rate      | Back to the queue per Retry-After; does not count toward the max attempts |
| 429 daily_limit             | Pause the dispatcher until the next UTC day                               |
| 503 overloaded              | Back off per Retry-After                                                  |
| timeout, cut, 500, 502, 504 | Ambiguous: retry with the same key and back off                           |
| 400, 422                    | No earlier ambiguity: local failure. With ambiguity: reconcile            |
| 401                         | Pause the dispatcher and alert the admin. Nothing is failed               |
| 409 idempotency_key_reused  | Reconcile and alert                                                       |

- Cap: 6 failed attempts or 15 minutes. If there was ambiguity, it reconciles before failing: GET /v1/generations looking for metadata.local_id (paging back to the creation date). Found: adopted. Not found: local failure, hold released. If the lookup fails, nothing is decided: it is retried.

**Status** (a single service for POST, webhook, polling and reconciliation)

- Row lock. queued < processing < final; never goes back.
- succeeded and failed reported by the provider are immutable.
- A local failed is provisional against the provider: if a succeeded for the same local_id arrives later, the provider wins, it is charged and the notice is logged.
- Settlement in the same transaction: release of the hold and, on success, charge of the real price. Failed: charge 0. The ledger's unique index makes a double charge impossible.
- If the real price is above the hold (rates changed), cost plus markup is charged anyway; the balance can go negative, which blocks new generations, and a notice is logged.

**Webhooks**

- POST without CSRF. HMAC-SHA256 of "timestamp.raw_body", constant-time comparison, 300 s tolerance (each retry comes signed with a new time). Invalid signature: 400.
- Same transaction: insert event_id (unique) and apply the status. Duplicate: 204 with no effect. Error while applying: rollback and 5xx so it retries.
- Lookup by provider_id or metadata.local_id (it can arrive before the POST response).
- ping: record and 204. No matching generation: record, log a warning and 204.

**Backup polling**

- Scheduler every 10 s: accepted ones with no final state and no news for 15 s or more, sent with the current API key.
- One limiter for POST, GET and reconciliation: 50 per minute, honours Retry-After and X-RateLimit-Remaining.

**Retry**

- Only on failed ones (a local one, already reconciled). Creates a new generation with a new ULID, key and hold, re-estimated with the current rates and markup. retry_of_id is unique: a double click does not duplicate.

**History** - Paginated list; the UI polls our own database (usePoll) while there are non-final states. Expired image: "Image expired" placeholder.

**Balances** - Balance, fictitious packs (topup, idempotent per form), blocked with a message that says how much is missing.

**Admin**

- Report by day (settings time zone) and by user: charged, provider cost, profit, grouped by completed_at.
- Reconciliation: always UTC days, current API key only, exact comparison of cost and count against /v1/usage, visible difference, "Sync pending" button (forced polling pass plus the dispatcher, once per minute).
- Global settings, cached in Redis until the next save.

**Demo data** - `php artisan pixelforge:demo` queues 5 example generations for the demo account through the normal flow, including one `[slow]` and one `[fail]`. Fixed form tokens: a second run creates nothing.

## 9. External integrations

Pixelforge /v1: pricing, generations (POST, GET, list), usage, webhooks. 10 s timeout. Variables: PIXELFORGE_API_KEY, PIXELFORGE_WEBHOOK_SECRET, PIXELFORGE_BASE_URL. One key for local and another for production; limits are per account and shared.

## 9b. Error handling

- Unhandled exceptions: email to ADMIN_EMAIL (not in local or testing, no keys or secrets, rate limited).
- Business exceptions: InsufficientBalance, DailyLimitReached, GenerationNotRetryable, InvalidWebhookSignature, ProviderRejected, ProviderUnavailable, UnsupportedRate, InvalidMarkupSetting.
- To the user: a clear message by error_code. Technical detail only in logs.

## 10. Architecture decisions

Full log: [DECISIONS.md](DECISIONS.md).

| Decision                                 | Rejected                           | Why                                                     |
| ---------------------------------------- | ---------------------------------- | ------------------------------------------------------- |
| Integer nano-USD                         | Cents / DECIMAL with floats in PHP | Exact and matches the 9 decimals of usage               |
| Own queue and async send                 | POST inside the web request        | One path for limits, retries and the visible queue      |
| Append-only ledger + cached balance      | Balance column only                | Auditable; the unique index stops a double charge       |
| Persist before calling + idempotency     | Create the record on the 202       | The webhook can arrive first; a cut never loses charges |
| One status service                       | Separate logic per source          | Same rules for duplicates and out-of-order events       |
| Webhook processed in the request         | Queue it                           | It is fast and the 2xx means applied                    |
| Database queue                           | Redis queue                        | Less infrastructure                                     |
| Markup stored per generation             | Current markup at settlement       | The charge always matches what was held (DEC-011)       |
| Redis cache, settings cached until saved | Database cache                     | Settings are read on almost every request (DEC-012)     |

Adversarial doubt (one round, clean context): 5 objections (2 high), all 5 accepted and built in: a way out for ambiguous attempts, attempt mark before the POST and reversible local failure, charging above the hold, the definition of retry, reconciliation per API key and a single limiter.

Known risks: the local environment uses the same limits as production; if Pixelforge accepts a request after a negative reconciliation, it is corrected only when its webhook arrives (covered by the reversible local failure); with no webhook, it stays visible in reconciliation.

## 11. Conventions

C12: the FormRequest validates and authorizes (or a Policy), the Service holds the logic, the controller is thin.

- app/Services/Pixelforge/PixelforgeClient
- app/Services/{PricingService, GenerationService, DispatchService, GenerationStateService, LedgerService, WebhookService, ReportService, PollingService}
- app/Support/Money
- app/Jobs/{DispatchPendingGenerations, SyncOpenGenerations}
- app/Console/Commands/CreateDemoGenerations
- resources/js/pages/{generations, credits, admin}

Code, commits and README in English. Docs in English with a Spanish version.

## 11a. Design

- Core idea: each generation shows a cost "ticket": estimated range, held, final price.
- Colours: background #F7F8FA, text #14161F, action #2B44D9, success #17805A, waiting #A86400, error #B3261E.
- Type: IBM Plex Sans, tabular numbers for amounts.
- Layout: form and estimate on top, history below; one column on phones.
- Minimal motion: status changes and button feedback; honours prefers-reduced-motion. States carry text, not just colour.

## 11b. Quality bar

The commands in force are in [CONSTRAINTS.md](../CONSTRAINTS.md).

| Check              | Command                                              | When                                |
| ------------------ | ---------------------------------------------------- | ----------------------------------- |
| PHP format         | vendor/bin/pint --dirty                              | Every change                        |
| Front format, lint | npm run check                                        | Every change                        |
| Front types        | npm run types:check                                  | Every change in resources/js        |
| Static analysis    | vendor/bin/phpstan analyse <files> --memory-limit=1G | Every change                        |
| Module tests       | php artisan test --filter=<Module>                   | Every change                        |
| Suite              | php artisan test                                     | End of each stage and before deploy |
| Build              | npm run build                                        | Before deploy                       |

0 errors in touched files; no new dependencies without OK; silencing checks or skipping tests is forbidden.
Mandatory tests: pricing math, single settlement, signature, duplicates, out-of-order events, webhook before the POST response, ambiguous result, insufficient balance, double submit.

## 12. Stage plan

1. [done] Scaffold, auth, PostgreSQL, constraints.
2. [done] Money + Pricing + tests.
3. [done] Client, create, dispatcher, status, ledger.
4. [done] Webhooks + polling + reconciliation.
5. [done] Form and history.
6. [done] Balances.
7. [done] Admin, settings, reconciliation, editable markup, Redis cache.
8. [done] README, demo data, final local check against the real API (polling path).
9. [pending] Deploy on the VPS (the owner does it with the README guide) and test real webhooks. Delivery.

## 13. Open questions

1. ~~Pixelforge account and API keys~~ Answered: created; the local key was used for the stage 8 check.
2. ~~VPS~~ Answered: Virtualmin + Apache, domain pixelforge.mazar.com.ar, the owner deploys, at the end.
3. Is there SMTP for error alerts? Without it, reports only go to the log.
4. ~~Repo~~ Answered: github.com/matiazar/pixelforge, private during development, public for delivery.

## 14. Estimate

Ranges with agent-assisted development, including the owner's review. Low precision: an a priori estimate.

| Module / task                               | Hours         | Assumption that moves it most                  |
| ------------------------------------------- | ------------- | ---------------------------------------------- |
| Setup, auth, constraints                    | 0.5 - 1       | PHP and Node ready locally                     |
| Money and estimate                          | 1 - 1.5       | Rates as documented                            |
| Create, dispatcher, status, ledger          | 2 - 3         | Ambiguous cases and concurrency (highest risk) |
| Webhooks, polling, reconciliation           | 0.75 - 1      | Tests against the real API                     |
| Form and history                            | 1 - 1.5       | Little visual polish                           |
| VPS deploy                                  | 0.75 - 2      | Current state of the server                    |
| **Required**                                | **6 - 10**    |                                                |
| Balances (UI and blocking; ledger included) | 0.75 - 1.5    |                                                |
| Admin, settings, reconciliation             | 1.5 - 2.5     | Time zones                                     |
| Final QA, README, demo, delivery            | 0.75 - 1.5    | Fixes after testing in production              |
| **Total without learning**                  | **9 - 15.5**  |                                                |
| Learning mode                               | 1 - 2         | Depth of the explanations                      |
| **Total**                                   | **10 - 17.5** |                                                |

## Change log

- 2026-11-26: first version.
- 2026-11-26: approved. VPS web server: Apache (not Nginx).
- 2026-11-26: stage 3: local `pending` status; form_token unique per user; an exhausted daily quota fails the non-ambiguous pending ones at once; dispatcher with at most 5 attempts per run. API detail in PIXELFORGE-API.md.
- 2026-11-26: deploy moved to the last stage (owner's request); Virtualmin VPS, domain pixelforge.mazar.com.ar; private repo.
- 2026-11-26: stage 5: retry re-estimates with current rates; account seeder brought forward; estimate throttled to 120/min. Magic-word test moved to stage 6 (needs balance).
- 2026-11-26: stage 6: Credits page; account deletion removed (owner's request, it was already out of scope); business rejections not reported to the admin.
- 2026-11-26: stage 7: admin area (report, reconciliation, settings); reconciliation assumes the UTC day of completed_at; Sync pending queues the dispatcher; closed registration blocks /register.
- 2026-11-26: stage 7 fixes: markup editable by the admin and stored per generation (DEC-011); Redis cache with settings cached until saved (DEC-012); polling only for the current API key; reconciliation compares cost and count.
- 2026-11-26: stage 8: `pixelforge:demo` command, README rewritten, final local check. Docs in English with a Spanish version.
