# Constraints: Pixelforge Studio

## Checks

| Check               | Command                                                | When                                        |
| ------------------- | ------------------------------------------------------ | ------------------------------------------- |
| PHP format          | `vendor/bin/pint --dirty`                              | Every change, touched files only            |
| Front format + lint | `npm run check` (Vite+ : oxfmt/oxlint)                 | Every change                                |
| Front types         | `npm run types:check`                                  | Every change that touches `resources/js`    |
| Static analysis     | `vendor/bin/phpstan analyse <files> --memory-limit=1G` | Every change, touched files only            |
| Module tests        | `php artisan test --filter=<Module>`                   | Every change                                |
| Full suite          | `php artisan test`                                     | End of each stage, before deploy            |
| Build               | `npm run build`                                        | Before deploy, or when build config changes |

Tests run against the PostgreSQL database `pixelforge_testing` (see `phpunit.xml`), never the dev database.

## Process

- TDD for services and controllers: write the failing test, watch it fail for the right reason, then the minimal code (owner's request, from stage 3)

## Thresholds

- Format and lint: 0 errors in touched files. Pre-existing warnings elsewhere do not block
- Tests: the touched module's tests green. A test that failed before and still fails is reported, not hidden
- No new dependencies without the owner's OK
- Mandatory tests: pricing math, single settlement, webhook signature, duplicate webhooks, out-of-order events, webhook before POST response, ambiguous attempt, insufficient balance, double submit
- Money is integer nano-USD end to end. No floats, also not in the frontend

## Forbidden to get to green

None of these is added without the owner's explicit OK, and if approved it carries a comment with the reason:

- Silencing lint or static analysis: `eslint-disable`, `oxlint-disable`, `// @ts-ignore`, `// @ts-expect-error` without reason, `@phpstan-ignore`, `@psalm-suppress`
- Skipping or trimming tests: `->skip()`, `->only()`, `markTestSkipped`, `@group` to exclude
- Deleting or weakening an assert to make it pass
- Lowering thresholds in config (`phpstan.neon` level, lint rules, `--max-warnings`)
- Excluding files or folders from lint or tests
- `git commit --no-verify` or skipping hooks
- Empty `catch` or a `catch` that only logs so the test does not blow up
- Mocking the unit under test
