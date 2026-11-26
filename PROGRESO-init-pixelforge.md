# Progreso: Init Pixelforge Studio

## Etapas

- [COMPLETA] Etapa 1: Scaffold, auth, PostgreSQL, constraints (commit: 28f8ac5)
- [COMPLETA] Etapa 2: Money + Pricing + tests (commit: cf732c5)
- [COMPLETA] Etapa 3: Cliente, alta, despachador, estado, ledger (commit: pendiente)
- [PENDIENTE] Etapa 4: Webhooks + polling + reconciliacion
- [PENDIENTE] Etapa 5: Formulario e historial
- [PENDIENTE] Etapa 6: Saldos
- [PENDIENTE] Etapa 7: Admin, ajustes, conciliacion
- [PENDIENTE] Etapa 8: README, datos demo, verificacion final local
- [PENDIENTE] Etapa 9: Deploy en VPS (lo hace el usuario: Virtualmin + Apache, pixelforge.mazar.com.ar) y entrega

## Decisiones tomadas sin el usuario

- "si" del usuario tomado como: spec aprobado, commits locales por etapa autorizados, modo aprendizaje mantenido
- Starter kit desde la rama main (las versiones tag de Packagist traen Laravel 12; main trae 13)
- PHPUnit reemplazado por Pest (el spec pide Pest); los tests del kit siguen como clases PHPUnit, Pest los corre igual
- Front: el kit usa Vite+ (`npm run check`) en vez de eslint/prettier separados; CONSTRAINTS.md usa los comandos reales. Falta reflejarlo en la seccion 11b del spec al cierre
- `/CLAUDE.md` sacado del .gitignore del kit para que se commitee
- CI del kit (.github/workflows/tests.yml) con servicio Postgres 17 para que corra en GitHub
- /security-review no corrio en la etapa 1: requiere origin/HEAD y el repo no tenia remoto. Queda para los reviews al pushear
- Conteo de caracteres del prompt: code points (mb_strlen). Verificar contra la API real con un prompt con emoji (finding del review, pendiente)
- Desde la etapa 3: TDD (pedido del usuario). La etapa 2 tuvo tests escritos junto al codigo, no test-first
- Estado local `pending` (en nuestra cola, antes del 202) agregado antes de queued
- form_token unico por usuario (user_id, form_token), no global
- Cuota diaria del proveedor agotada: las pendientes no ambiguas fallan en el acto (sin cobro) en vez de esperar hasta medianoche; el despachador queda pausado hasta 00:00 UTC
- Despachador: maximo 5 intentos por corrida (el lock dura 60 s y cada POST puede tardar 10 s)
- El tope de 15 minutos cuenta desde la creacion, incluida la espera en nuestra cola
- 401 de la API: despachador pausado 10 min + mail al admin
- Pendiente etapa 5: el kit exige email verificado (`verified` + MustVerifyEmail) y el spec dice sin verificacion de mail; hay que sacarlo para que el registro abierto funcione
- Repo GitHub privado `pixelforge` (pedido del usuario); pasa a publico para la entrega

Ultima actualizacion: etapa 3 completa
