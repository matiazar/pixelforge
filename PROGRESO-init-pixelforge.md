# Progreso: Init Pixelforge Studio

## Etapas

- [COMPLETA] Etapa 1: Scaffold, auth, PostgreSQL, constraints (commit: 28f8ac5)
- [COMPLETA] Etapa 2: Money + Pricing + tests (commit: cf732c5)
- [COMPLETA] Etapa 3: Cliente, alta, despachador, estado, ledger (commit: 1248fe3)
- [COMPLETA] Etapa 4: Webhooks + polling + reconciliacion (commit: 18c4f00)
- [COMPLETA] Etapa 5: Formulario e historial (commit: d7ca4ab)
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
- Webhook en `routes/webhooks.php`, fuera del grupo `web` (sin sesion, cookies ni CSRF; lo autentica la firma HMAC)
- Polling: un 404 del proveedor para una generacion abierta la falla local (`not_found_at_provider`) y libera la reserva
- Polling: un payload que no se puede aplicar se reporta al admin y se saltea; no frena al resto
- Firma con varios `v1` (rotacion de secreto): se acepta si alguno coincide
- Etapa 5: verificacion de mail sacada (feature de Fortify, MustVerifyEmail, middleware `verified`, vista y 2 tests del kit de esa feature)
- Etapa 5: seeder de cuentas admin y demo adelantado de la etapa 8 (demo arranca con 20 USD ficticios; si el mail de admin ya esta registrado sin admin, el seeder falla en vez de promoverlo)
- Etapa 5: reintento = generacion nueva con el mismo pedido, precio re-estimado con las tarifas actuales, mismos chequeos de limite diario y saldo
- Etapa 5: estimado en vivo con debounce 400 ms y throttle 120/min por usuario
- Etapa 5 (arreglo pedido por el usuario): borrar cuenta sacado del perfil (ruta, accion, request, componente). Fuera de alcance en el spec: borraria registros de dinero. El admin borrado en local se recreo con el seeder
- Etapa 5: links del kit (Repository, Documentation) sacados del menu; item "Dashboard" renombrado "Create"
- Pendiente etapa 6: prueba de palabras magicas contra la API real. La cuenta de prueba creada en local tiene saldo 0 y no hay como cargarle credito hasta "Buy credits"; las contrasenas del seeder en .env no las leo
- Repo GitHub privado `pixelforge` (pedido del usuario); pasa a publico para la entrega

Ultima actualizacion: etapa 5 completa
