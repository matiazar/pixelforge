# Pixelforge Studio

Web app to generate images with the Pixelforge API. Users see a price range before submitting, pay the provider cost + 25% from a fictitious balance, and follow each generation live. Admins see spend, provider cost and profit.

## Requirements

- PHP 8.3+, Composer
- Node 22+
- PostgreSQL 15+

## Local setup

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
```

Create two databases (`pixelforge` and `pixelforge_testing`), fill `DB_*` in `.env`, then:

```bash
php artisan migrate --seed
composer dev
```

## Environment

| Variable                    | Purpose                                                |
| --------------------------- | ------------------------------------------------------ |
| `PIXELFORGE_API_KEY`        | Provider API key (use a different key per environment) |
| `PIXELFORGE_WEBHOOK_SECRET` | Secret to verify webhook signatures                    |
| `PIXELFORGE_BASE_URL`       | Provider base URL                                      |
| `ADMIN_EMAIL`               | Receives unhandled exception reports in production     |
| `SEED_*`                    | Demo and admin accounts created by the seeder          |

## Tests

```bash
php artisan test
```

Tests run against `pixelforge_testing`.
