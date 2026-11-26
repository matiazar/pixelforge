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
