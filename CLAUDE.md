# Pixelforge Studio

Image generation app on the Pixelforge API (simulated). Users pay provider cost + markup (25% by default, admin setting, stored per generation) from a fictitious balance; admins see spend, cost and profit. Full spec: `docs/SPEC.md` (Spanish: `docs/SPEC-TECNICO.md`).

## Stack

Laravel 13 on PHP 8.4, Vue 3 + Inertia (official Vue starter kit: TypeScript, Tailwind, shadcn-vue, Fortify), PostgreSQL, Redis cache, database queue + scheduler, Pest.

## Commands

- Run: `composer dev` (server, queue, vite) plus `php artisan schedule:work` (dispatcher and polling)
- Migrate: `php artisan migrate`
- Demo data: `php artisan db:seed` then `php artisan pixelforge:demo`
- Module tests: `php artisan test --filter=<Module>`
- Full suite: `php artisan test`
- Format/lint: `vendor/bin/pint --dirty`, `npm run check`, `npm run types:check`
- Build: `npm run build`

Tests use the PostgreSQL DB `pixelforge_testing` (phpunit.xml). Never point tests at the dev DB.

## Testing

- TDD for services and controllers: failing test first, then minimal code (see CONSTRAINTS.md)

## Conventions (C12)

- Validation and authorization in FormRequest (or Policy); thin controllers
- Business logic in `app/Services` (`PricingService`, `GenerationService`, `DispatchService`, `GenerationStateService`, `LedgerService`, `WebhookService`, `ReportService`); `app/Services/Pixelforge/PixelforgeClient` for HTTP
- Money: integer nano-USD only, via `app/Support/Money`. No floats, also in the frontend (server sends formatted strings)
- Only `GenerationStateService` changes a generation's `status`; only `LedgerService` writes balances
- Business rules throw named exceptions (`InsufficientBalance`, `DailyLimitReached`, ...). No empty catches
- Code and commits in English. Docs in English with a Spanish version (`*.es.md`, or the Spanish-named sibling in `docs/`); a doc change goes into both

## Project files

- `CONSTRAINTS.md` + `CONSTRAINTS.es.md`: quality bar and what is forbidden to get to green
- `docs/DECISIONS.md` + `docs/DECISIONES.md`: architecture decisions (append-only; add each entry to both)
- `docs/SPEC.md` + `docs/SPEC-TECNICO.md`: technical spec (English + Spanish; keep both in sync)

## Flujo de trabajo

- Todo cambio de codigo en este proyecto (modulo, arreglo, ajuste) sigue mazar-dev
- Antes de cada commit corre mazar-review sobre los cambios
