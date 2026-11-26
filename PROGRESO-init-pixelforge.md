# Progreso: Init Pixelforge Studio

## Etapas

- [COMPLETA] Etapa 1: Scaffold, auth, PostgreSQL, constraints (commit: ver git log, init(etapa 1))
- [PENDIENTE] Etapa 2: Deploy minimo en el VPS (Apache) - necesita datos del VPS y OK para deploy
- [PENDIENTE] Etapa 3: Money + Pricing + tests
- [PENDIENTE] Etapa 4: Cliente, alta, despachador, estado, ledger
- [PENDIENTE] Etapa 5: Webhooks + polling + reconciliacion
- [PENDIENTE] Etapa 6: Formulario e historial + deploy completo
- [PENDIENTE] Etapa 7: Saldos
- [PENDIENTE] Etapa 8: Admin, ajustes, conciliacion
- [PENDIENTE] Etapa 9: README, datos demo, verificacion final, entrega

## Decisiones tomadas sin el usuario

- "si" del usuario tomado como: spec aprobado, commits locales por etapa autorizados, modo aprendizaje mantenido
- Starter kit desde la rama main (las versiones tag de Packagist traen Laravel 12; main trae 13)
- PHPUnit reemplazado por Pest (el spec pide Pest); los tests del kit siguen como clases PHPUnit, Pest los corre igual
- Front: el kit usa Vite+ (`npm run check`) en vez de eslint/prettier separados; CONSTRAINTS.md usa los comandos reales. Falta reflejarlo en la seccion 11b del spec al cierre
- `/CLAUDE.md` sacado del .gitignore del kit para que se commitee
- CI del kit (.github/workflows/tests.yml) con servicio Postgres 17 para que corra en GitHub
- /security-review no corrio en la etapa 1: requiere origin/HEAD y el repo no tenia remoto. Queda para los reviews al pushear
- Repo GitHub privado `pixelforge` (pedido del usuario); pasa a publico para la entrega

Ultima actualizacion: etapa 1 completa
