# Pixelforge Studio

Web app to generate images with the Pixelforge API. Users see a price range before they submit, pay the provider cost plus a markup (25% by default, set by the admin) from a fictitious balance, and follow each generation live. Admins see spend, provider cost and profit per day and per user, and reconcile it against Pixelforge's own usage numbers.

Built with Laravel 13, Vue 3 + Inertia (official Vue starter kit), PostgreSQL, Redis (cache) and a database queue.

## Contents

- [How money works](#how-money-works)
- [How a generation travels](#how-a-generation-travels)
- [Local setup](#local-setup)
- [Demo data](#demo-data)
- [Tests and checks](#tests-and-checks)
- [Deploy (VPS with Apache)](#deploy-vps-with-apache)
- [Decisions and known limits](#decisions-and-known-limits)

## How money works

- **Integers only.** Every amount is an integer of nano-USD (1 USD = 1,000,000,000). Pixelforge reports costs with 9 decimals, so this is exact. Rates arrive as decimal strings and are converted without floats; a rate that is not a whole nano per token is refused. The browser never does money math: the server sends formatted strings.
- **Estimate.** Built from the live `GET /v1/pricing` table (cached 5 minutes, documented rates as fallback): text tokens from the prompt length with the documented +-15%, 765 tokens per reference image, output tokens by size and quality, and the reasoning range by quality. The user sees a min-max price range.
- **Markup.** Price = cost x (100 + markup) / 100, rounded up to the next nano. The markup in force is read once when the generation is created and stored on it; the estimate, the hold and the final charge all use that stored value, so changing the setting never reprices a generation in flight.
- **Hold.** On submit the maximum price is held from the balance (in one transaction with the user row locked, together with the daily limit check). Not enough balance: the request is refused and says how much is missing.
- **Settlement.** When Pixelforge reports the final state, the hold is released and, on success, the real price is charged: `usage` x the `rates` fixed on that generation. Failed generations cost nothing. Release and charge happen in one transaction, and a unique `(generation_id, type)` in the ledger makes a second charge impossible.
- **Ledger.** `ledger_entries` is append-only (`topup`, `hold`, `release`, `charge`); `users.balance_nano` is a cache of it, written only by `LedgerService`. "Buy credits" adds fictitious top-ups (5, 10 or 20 USD), idempotent per form.

## How a generation travels

1. **Submit.** The web request only validates, holds and stores the generation as `pending`. Its ULID is also the `Idempotency-Key` and `metadata.local_id`. A form token stops double submits.
2. **Dispatch.** A queued dispatcher (locked, FIFO) sends it when fewer than 3 are in flight. Before each POST it marks the attempt as ambiguous. 429 and 503 back off with `Retry-After`, a daily limit pauses until 00:00 UTC, a 401 pauses and mails the admin. Timeouts and 5xx are retried with the same key. After 6 failed attempts or 15 minutes, an ambiguous generation is first looked up by `local_id` at Pixelforge before it is failed and its hold released.
3. **Status.** Webhooks are the main path: signature verified (HMAC-SHA256 of `timestamp.raw body`, 300 s tolerance), each event id stored once, events matched by provider id or `local_id` (one can arrive before the POST response). Status only moves forward (queued, processing, final) and a final state from the provider never changes. Polling every 10 s is the backup for generations without news for 15 s. A single limiter (50 calls per minute) covers every call to Pixelforge.
4. **History.** The dashboard polls our own database while something is still open, shows estimated and actual price, and lets the user retry failed ones (a new generation with a new hold). Expired image URLs show a placeholder.

Admin (users with `is_admin`, set only by the seeder):

- **Report**: charged, provider cost and profit by day (in the settings time zone) and by user, by completion time.
- **Reconciliation**: our provider cost per UTC day against `GET /v1/usage` for the current API key, compared exactly, with the generation count. "Sync pending" queues a forced polling pass and the dispatcher (once per minute).
- **Settings**: report time zone, daily limit per user, registration open or closed, markup. Settings are cached in Redis until the next save.

## Local setup

Requirements: PHP 8.4 (`pdo_pgsql`, `redis`, `intl`, `mbstring`, `zip`), Composer, Node 22+, PostgreSQL 15+, Redis.

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
```

Create the databases `pixelforge` and `pixelforge_testing`, then fill `.env`:

| Variable                                  | Purpose                                                              |
| ----------------------------------------- | -------------------------------------------------------------------- |
| `DB_*`                                    | PostgreSQL connection (dev database `pixelforge`)                    |
| `REDIS_*`, `CACHE_STORE=redis`            | Cache, shared API limiter, job locks and settings                    |
| `PIXELFORGE_API_KEY`                      | Provider key. Use a different key per environment                    |
| `PIXELFORGE_WEBHOOK_SECRET`               | `whsec_...` secret to verify webhook signatures                      |
| `PIXELFORGE_BASE_URL`                     | `https://pixelforge.promptedit.dev`                                  |
| `ADMIN_EMAIL`                             | Receives unhandled exception reports in production (needs SMTP)      |
| `SEED_ADMIN_EMAIL`, `SEED_ADMIN_PASSWORD` | Admin account created by the seeder                                  |
| `SEED_DEMO_EMAIL`, `SEED_DEMO_PASSWORD`   | Demo account created by the seeder, with 20 USD of fictitious credit |

```bash
php artisan migrate --seed
composer dev               # web server, queue worker and Vite
php artisan schedule:work  # in a second terminal: dispatcher and polling every 10 s
```

Webhooks cannot reach `localhost`; locally the polling backup updates the status. To try real webhooks, expose the app with a tunnel (Expose, ngrok, Cloudflare Tunnel) and point the Pixelforge webhook to `<tunnel>/webhooks/pixelforge`.

Pixelforge "magic words" in a prompt are handy to try each path: `[fail]`, `[slow]`, `[blocked]`, `[overloaded]`, `[duplicate]`, `[succeed]`.

## Demo data

```bash
php artisan db:seed          # admin + demo accounts (safe to run twice)
php artisan pixelforge:demo  # queues 5 example generations for the demo account
```

`pixelforge:demo` sends the examples through the normal flow (estimate, hold, dispatcher), including one `[slow]` and one `[fail]` to show live status and the retry button. Running it again does nothing. It holds about 0.70 USD of the 20 USD demo credit; the real charge is lower and the failed one is free.

## Tests and checks

```bash
php artisan test                                        # full suite (Pest), against pixelforge_testing
php artisan test --filter=GenerationStateService        # one module
vendor/bin/pint --dirty                                 # PHP format
vendor/bin/phpstan analyse <files> --memory-limit=1G    # static analysis
npm run check && npm run types:check                    # front format, lint, types
npm run build
```

Tests never use the dev database (`phpunit.xml` points to `pixelforge_testing`, cache `array`, queue `sync`). The quality bar and what is not allowed to get to green are in `CONSTRAINTS.md`.

## Deploy (VPS with Apache)

Target: a VPS with Virtualmin, Apache and PHP-FPM, e.g. `https://pixelforge.example.com`.

1. **Server packages**: PHP 8.4 FPM with `pdo_pgsql`, `redis`, `intl`, `mbstring`, `zip`; PostgreSQL; Redis; Supervisor; Node 22 (build only).
2. **Code**:
    ```bash
    git clone <repo> && cd pixelforge
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build      # needs php 8.4 on PATH (route helpers are generated at build time)
    cp .env.example .env && php artisan key:generate
    ```
    In `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, the production `PIXELFORGE_*` values, `DB_*`, `REDIS_*`, `MAIL_*`, `ADMIN_EMAIL`, `SEED_*`.
3. **Database and demo**:
    ```bash
    php artisan migrate --force
    php artisan db:seed --force
    php artisan pixelforge:demo
    php artisan config:cache && php artisan route:cache && php artisan view:cache
    ```
4. **Apache**: the virtual host's document root is `public/`, with `AllowOverride All` (the `.htaccess` rewrites to `index.php`) and HTTPS (Let's Encrypt from Virtualmin). Do not add filters that rewrite request bodies: the webhook signature is checked on the raw body.
5. **Queue worker** (Supervisor):
    ```ini
    [program:pixelforge-worker]
    command=php /path/to/pixelforge/artisan queue:work --sleep=1 --max-time=3600
    user=<site user>
    autostart=true
    autorestart=true
    stopwaitsecs=30
    ```
6. **Scheduler** (cron, every minute; it runs the 10-second dispatcher and polling inside the minute):
    ```
    * * * * * cd /path/to/pixelforge && php artisan schedule:run >> /dev/null 2>&1
    ```
7. **Webhook**: in Pixelforge, point the webhook to `https://<domain>/webhooks/pixelforge` and put its secret in `PIXELFORGE_WEBHOOK_SECRET`.
8. **After each deploy**: `php artisan migrate --force`, the three `*:cache` commands, and `php artisan queue:restart`. Do not run `cache:clear` if Redis is shared with other apps: it empties the whole cache database.

## Decisions and known limits

The full log is in `docs/DECISIONES.md` and the spec in `docs/SPEC-TECNICO.md`. In short:

- **Money as integer nano-USD**, append-only ledger, hold of the maximum price. If rates change between estimate and completion, the real price is charged even above the hold; the balance can go negative, which blocks new generations.
- **Own queue in front of Pixelforge** (3 in flight, FIFO) so a user never sees a provider 429. Each generation is stored before the POST, and the same idempotency key is used on every retry; ambiguous sends are reconciled by `local_id` before giving up.
- **One state service** for POST responses, webhooks, polling and reconciliation, with a row lock: status only moves forward and a provider's final state is never changed. A local failure stays reversible: if Pixelforge later reports success for it, the provider wins and it is charged.
- **Markup stored per generation** (like the provider rates). The live estimate on the form can be from before a markup change; the hold and the charge use the markup read on submit.
- **Redis for the cache** (settings, the shared API limiter, job locks), database queue. If Redis is down, pages that read settings fail. A settings change made directly in the database is not seen until the next save from the admin page.
- **Reconciliation is per API key**: generations sent with an earlier key are not polled or compared (a lookup with the new key would answer 404).
- **Out of scope**: real payments, keeping images after their URL expires, cancelling a generation, email verification and password reset by email, account deletion (it would destroy money records), other languages.
