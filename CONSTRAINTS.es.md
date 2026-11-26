# Constraints: Pixelforge Studio

English: [CONSTRAINTS.md](CONSTRAINTS.md)

## Chequeos

| Chequeo              | Comando                                                   | Cuando                                          |
| -------------------- | --------------------------------------------------------- | ----------------------------------------------- |
| Formato PHP          | `vendor/bin/pint --dirty`                                 | Cada cambio, solo archivos tocados              |
| Formato y lint front | `npm run check` (Vite+ : oxfmt/oxlint)                    | Cada cambio                                     |
| Tipos front          | `npm run types:check`                                     | Cada cambio que toca `resources/js`             |
| Analisis estatico    | `vendor/bin/phpstan analyse <archivos> --memory-limit=1G` | Cada cambio, solo archivos tocados              |
| Tests del modulo     | `php artisan test --filter=<Modulo>`                      | Cada cambio                                     |
| Suite completa       | `php artisan test`                                        | Cierre de cada etapa, antes de deploy           |
| Build                | `npm run build`                                           | Antes de deploy, o si cambia la config de build |

Los tests corren contra la base PostgreSQL `pixelforge_testing` (ver `phpunit.xml`), nunca contra la de desarrollo.

## Proceso

- TDD para services y controllers: escribir el test que falla, verlo fallar por el motivo correcto, despues el codigo minimo (pedido del dueno, desde la etapa 3)

## Umbrales

- Formato y lint: 0 errores en archivos tocados. Warnings previos en otros archivos no bloquean
- Tests: los del modulo tocado en verde. Un test que ya fallaba y sigue fallando se reporta, no se esconde
- Sin dependencias nuevas sin OK del dueno
- Tests obligatorios: calculo de precios, liquidacion unica, firma del webhook, webhooks duplicados, eventos en desorden, webhook antes de la respuesta del POST, intento ambiguo, saldo insuficiente, doble envio
- Dinero en enteros nano-USD de punta a punta. Sin floats, tampoco en el frontend

## Prohibido para llegar a verde

Nada de esto se agrega sin OK explicito del dueno, y si se aprueba lleva un comentario con el motivo:

- Silenciar lint o analisis estatico: `eslint-disable`, `oxlint-disable`, `// @ts-ignore`, `// @ts-expect-error` sin motivo, `@phpstan-ignore`, `@psalm-suppress`
- Saltear o recortar tests: `->skip()`, `->only()`, `markTestSkipped`, `@group` para excluir
- Borrar o debilitar un assert para que pase
- Bajar umbrales en la config (nivel de `phpstan.neon`, reglas de lint, `--max-warnings`)
- Excluir archivos o carpetas del lint o de los tests
- `git commit --no-verify` o saltear hooks
- `catch` vacio o un `catch` que solo loguea para que el test no explote
- Mockear la unidad bajo test
