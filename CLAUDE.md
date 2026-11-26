# Pixelforge Studio

Image generation app on the Pixelforge API (simulated). Users pay provider cost + 25% from a fictitious balance; admins see spend, cost and profit. Full spec: `docs/SPEC-TECNICO.md`.

## Stack

Laravel 13, Vue 3 + Inertia (official Vue starter kit: TypeScript, Tailwind, shadcn-vue, Fortify), PostgreSQL, database queue + scheduler, Pest.

## Commands

- Run: `composer dev` (server, queue, vite)
- Migrate: `php artisan migrate`
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
- Code, commits and README in English

## Project files

- `CONSTRAINTS.md`: quality bar and what is forbidden to get to green
- `docs/DECISIONES.md`: architecture decisions (append-only)
- `docs/SPEC-TECNICO.md`: technical spec

## Flujo de trabajo

- Todo cambio de codigo en este proyecto (modulo, arreglo, ajuste) sigue mazar-dev
- Antes de cada commit corre mazar-review sobre los cambios
