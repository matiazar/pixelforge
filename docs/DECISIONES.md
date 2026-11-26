# Decisiones: Pixelforge Studio

Registro de decisiones de arquitectura. Solo se agrega. Las decisiones que cambian se reemplazan, no se editan.

## DEC-001: Stack Laravel 13 + Vue 3 + Inertia (starter kit oficial)

Fecha: 2026-11-26
Estado: Vigente
Origen: spec tecnico

Contexto: la consigna exige Laravel + Vue; hace falta auth y entrega hoy.
Decision: starter kit oficial de Vue (Inertia, TypeScript, Tailwind, shadcn-vue, Fortify) sobre Laravel 13. Tests con Pest.
Alternativa descartada: API Laravel + SPA Vue separada.
Por que: el kit trae auth y routing resueltos; un solo deploy.
Consecuencias: frontend acoplado a Inertia; sin API publica.

## DEC-002: PostgreSQL

Fecha: 2026-11-26
Estado: Vigente
Origen: spec tecnico

Contexto: hay dinero, concurrencia (despachador, webhooks) y reportes por zona horaria.
Decision: PostgreSQL en dev, testing (`pixelforge_testing`) y produccion.
Alternativa descartada: SQLite / MySQL.
Por que: transacciones y locks de fila confiables, agrupacion por zona horaria con `AT TIME ZONE`.
Consecuencias: los tests necesitan un Postgres local.

## DEC-003: Dinero en enteros nano-USD

Fecha: 2026-11-26
Estado: Vigente
Origen: spec tecnico

Contexto: el proveedor informa costos con 9 decimales; se cobra costo + 25%.
Decision: BIGINT en nano-USD (1 USD = 1_000_000_000). Sin floats en ningun punto; el front recibe strings formateados.
Alternativa descartada: centavos, o DECIMAL con float en PHP.
Por que: exacto y coincide con los 9 decimales de usage.
Consecuencias: toda conversion pasa por `App\Support\Money`; tarifas que no den entero lanzan excepcion.

## DEC-004: Cola propia y envio asincrono al proveedor

Fecha: 2026-11-26
Estado: Vigente
Origen: spec tecnico

Contexto: el proveedor acepta maximo 3 generaciones en curso para toda la cuenta y tiene rate limits.
Decision: el request web solo crea la generacion y reserva saldo; un despachador (job con lock) hace el POST.
Alternativa descartada: POST dentro del request web.
Por que: un solo camino para limites, reintentos y cola visible al usuario.
Consecuencias: requiere worker (Supervisor) y scheduler (cron) en produccion.
Duda adversarial: aceptadas salida para intentos ambiguos y limitador unico de 50/min.

## DEC-005: Ledger de solo agregado + saldo cacheado

Fecha: 2026-11-26
Estado: Vigente
Origen: spec tecnico

Contexto: hay que reservar, liberar y cobrar sin cobrar dos veces.
Decision: `ledger_entries` (topup, hold, release, charge) con unico (generation_id, type) y `users.balance_nano` como cache.
Alternativa descartada: solo columna de saldo.
Por que: auditable; el indice unico impide doble cobro.
Consecuencias: toda escritura de saldo pasa por `LedgerService` dentro de una transaccion.
Duda adversarial: aceptado cobrar por encima de la reserva (saldo puede quedar negativo y bloquea).

## DEC-006: Persistir antes de llamar + Idempotency-Key

Fecha: 2026-11-26
Estado: Vigente
Origen: spec tecnico

Contexto: el webhook puede llegar antes que la respuesta del POST; un corte puede dejar el resultado incierto.
Decision: la generacion (ULID = Idempotency-Key = metadata.local_id) se guarda antes del POST, con marca `ambiguous` antes de llamar.
Alternativa descartada: crear el registro al recibir 202.
Por que: ningun webhook queda huerfano y un corte no pierde cobros.
Consecuencias: hace falta reconciliacion por metadata.local_id para intentos ambiguos.
Duda adversarial: aceptadas marca de intento antes del POST y falla local reversible.

## DEC-007: Un unico servicio de estado

Fecha: 2026-11-26
Estado: Vigente
Origen: spec tecnico

Contexto: el estado llega por POST, webhook, polling y reconciliacion, con duplicados y desorden.
Decision: `GenerationStateService` con lock de fila, orden queued < processing < final, finales del proveedor inmutables.
Alternativa descartada: logica separada por origen.
Por que: mismas reglas para duplicados y desorden en todos los caminos.
Consecuencias: ningun otro codigo cambia `status` directamente.

## DEC-008: Webhook procesado en el request

Fecha: 2026-11-26
Estado: Vigente
Origen: spec tecnico

Contexto: el proveedor reintenta si no recibe 2xx.
Decision: verificar firma, insertar event_id y aplicar estado en la misma transaccion; 2xx solo si se aplico.
Alternativa descartada: encolar el webhook.
Por que: es rapido y el 2xx significa aplicado; un fallo devuelve 5xx y el proveedor reintenta.
Consecuencias: el endpoint debe ser liviano y sin CSRF.

## DEC-009: Cola con driver database (sin Redis)

Fecha: 2026-11-26
Estado: Vigente
Origen: spec tecnico

Contexto: volumen chico, entrega hoy, VPS propio.
Decision: `QUEUE_CONNECTION=database` + scheduler.
Alternativa descartada: Redis.
Por que: menos infraestructura.
Consecuencias: locks de cache via database/file; revisar si el volumen crece.

## DEC-010: VPS con Apache

Fecha: 2026-11-26
Estado: Vigente
Origen: pedido del usuario

Contexto: el VPS del usuario ya corre Apache.
Decision: Apache (vhost + mod_rewrite) + PHP-FPM, Supervisor para el worker, cron para `schedule:run`, Let's Encrypt.
Alternativa descartada: Nginx (lo que proponia el borrador del spec).
Por que: es el servidor que ya existe en el VPS.
Consecuencias: el deploy se documenta para Apache; el webhook necesita el cuerpo crudo intacto (sin filtros de Apache que lo reescriban).

## DEC-011: Markup editable por el admin, guardado en cada generacion

Fecha: 2026-11-26
Estado: Vigente (reemplaza "25% fijo en config, no editable" del spec, seccion 2)
Origen: pedido del usuario

Contexto: la consigna dice "cost plus a 25% markup" pero no lo fija como no editable. El admin quiere ajustarlo.
Decision: ajuste global `markup_percent` (entero 0..100, default 25). Se lee una vez al crear la generacion, se usa para el estimado y la reserva y se guarda en `generations.markup_percent` (NOT NULL, sin default). El cobro final usa el de la generacion, nunca el vigente. Un valor guardado invalido lanza excepcion en vez de convertirse a 0.
Alternativa descartada: cobrar con el markup vigente al liquidar (el cobro podria no coincidir con lo reservado).
Por que: igual que las tarifas del proveedor, que se fijan al crear la generacion.
Consecuencias: las filas previas quedan en 25 (se cobraron asi). Riesgo conocido: el estimado en vivo del formulario puede ser de antes de un cambio de markup; la reserva y el cobro usan el markup leido al enviar. El reporte suma price/cost reales, pero no muestra que markup se aplico por fila.

## DEC-012: Cache en Redis; settings cacheados hasta editarlos

Fecha: 2026-11-26
Estado: Vigente (reemplaza la parte de cache de DEC-009; la cola sigue en database)
Origen: pedido del usuario

Contexto: los settings se leen en casi cada request (registro, Inertia, limite diario, markup).
Decision: `CACHE_STORE=redis` (phpredis) para toda la cache: pricing, rate limiter compartido de Pixelforge, locks de jobs unicos y settings. Settings: todas las filas en una clave sin vencimiento y versionada (`settings:<version>`). Al guardar (`Setting::putMany`, en transaccion) la version cambia despues del commit, asi un lector lento que leyo las filas viejas solo puede escribirlas bajo la version anterior, que ya nadie lee.
Alternativa descartada: solo settings en Redis y el resto en database.
Por que: pedido del usuario; menos carga en la DB.
Consecuencias: el VPS necesita Redis y la extension phpredis. Si Redis cae, la app falla en cada request que lee settings. Un cambio hecho directo en la tabla settings no se ve hasta guardar desde Settings o borrar la clave `settings:version`. No usar `cache:clear` si el Redis es compartido: vacia la DB de cache entera. Tests: store array (phpunit.xml).
