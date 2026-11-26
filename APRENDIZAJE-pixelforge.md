# Aprendizaje: Pixelforge Studio

## Etapa 1: Scaffold, auth, PostgreSQL, constraints

Conceptos nuevos:

- Reporte de errores en Laravel 11+: ya no existe `app/Exceptions/Handler.php`; se configura en `bootstrap/app.php` con `withExceptions()`. `$exceptions->report(fn)` agrega un callback sin cortar el log por defecto (solo lo corta si devuelve `false`).
  Diferencia clave: Laravel ya ignora el ruido (validacion, 404, 403, auth) antes de llamar al callback, asi que no hace falta un `dontReport` para eso.
- No filtrar datos del request en un reporte: `request()->route()->uri()` devuelve el patron (`reset-password/{token}`), no la URL real. Asi el token y el query string no salen del servidor.
- Throttle que no pierde avisos: el throttle se marca despues de encolar el mail (`Cache::has` + `Cache::put`), no antes. Si el envio falla, el siguiente error igual se reporta.

Donde verlo en el codigo: `app/Support/ErrorReporter.php:20`, `bootstrap/app.php:28`

Para practicar: agregar a `ErrorReporter` un test que verifique que el subject del mail incluye el entorno (`production: RuntimeException`).

## Etapa 2: Money + Pricing

Conceptos nuevos:

- Dinero en enteros: todo en nano-USD (`int`). Los strings decimales del proveedor ("12.50") se parsean con regex y `str_pad`, nunca con `(float)`. Una tarifa por 1M tokens en nano dividida 1_000_000 da nano por token; si no es exacta se rechaza (`UnsupportedRate`).
- Redondeo hacia arriba sin floats: `intdiv(n, d) + (n % d > 0 ? 1 : 0)`. Se usa para ceil(t x 1.15) y para el 25% de margen.
- Cache con fallback: la tabla viva se cachea 5 min; si el proveedor falla, el fallback de config se cachea 60 s para no pegarle en cada estimado.

Donde verlo en el codigo: `app/Support/Money.php:21`, `app/Services/PricingService.php:70`

Para practicar: agregar un test que verifique que `Money::format(-1)` devuelve "-0.000000001".

## Etapa 3: Cliente, alta, despachador, estado, ledger

Conceptos nuevos:

- Lock de fila en Postgres: `User::whereKey($id)->lockForUpdate()` dentro de `DB::transaction` serializa dos requests del mismo usuario. Asi el chequeo de saldo y la reserva no se pisan, y un doble submit encuentra la generacion ya creada.
- Marca "ambigua" antes de llamar afuera: se guarda (commit) `ambiguous = true` antes del POST. Si el worker muere a mitad de camino, la marca queda y la reconciliacion por `metadata.local_id` decide despues. La Idempotency-Key (el ULID) hace que reintentar no duplique.
- `afterCommit()` en jobs: `DispatchPendingGenerations::dispatch()->afterCommit()` solo encola si la transaccion se confirma; si hay rollback, el job no existe.
  Diferencia clave con un dispatch normal: sin `afterCommit` el worker podria leer la DB antes del commit y no ver la generacion.

Donde verlo en el codigo: `app/Services/GenerationService.php:37`, `app/Services/DispatchService.php:120`, `app/Services/GenerationStateService.php:35`

Para practicar: agregar un test que verifique que un 404 del proveedor en el POST, sin intento ambiguo previo, deja la generacion fallida local con la reserva liberada.
