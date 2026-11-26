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

## Etapa 4: Webhooks y polling

Conceptos nuevos:

- Firma HMAC sobre el body crudo: se firma `t.body` con el secreto y se compara con `hash_equals` (tiempo constante). Por eso el controller usa `$request->getContent()` y no `$request->all()`: re-serializar el JSON cambia los bytes y rompe la firma.
- Dedupe e idempotencia en la misma transaccion: `insertOrIgnore` del `event_id` y el cambio de estado van juntos. Si aplicar falla, el rollback borra tambien el evento, respondemos 5xx y el proveedor reintenta. Un 2xx significa "aplicado".
- Rutas fuera del grupo `web`: lo que se carga en `withRouting(then: ...)` no pasa por sesion, cookies ni CSRF. Es lo correcto para endpoints maquina a maquina.
  Diferencia clave con `withoutMiddleware`: en Laravel 13 el middleware de CSRF necesita la sesion, asi que sacar middlewares uno por uno rompe; sacar la ruta del grupo es mas limpio.

Donde verlo en el codigo: `app/Services/WebhookService.php:64`, `app/Services/PollingService.php:42`, `bootstrap/app.php:17`

Para practicar: agregar un test que verifique que un evento `generation.failed` para una generacion ya `succeeded` no cambia su estado ni el saldo.

## Etapa 5: Formulario e historial

Conceptos nuevos:

- API Resource como contrato con el front: `GenerationResource` decide que ve el usuario (su precio, nunca el costo del proveedor) y manda la plata como string formateado. Inertia resuelve el Resource paginado a `{data, links, meta}`.
  Equivalente en Blade: el array que se pasa a la vista, pero tipado y testeable con `assertInertia`.
- `usePoll` de Inertia: recarga parcial (`only: ['generations', 'flash']`) cada 3 s solo mientras hay generaciones abiertas. Ojo: una recarga parcial conserva los props que no pide, por eso `flash` va en la lista.
- `useHttp` (Inertia 3) para pedidos JSON que no navegan, como el estimado: maneja CSRF, `processing`, `errors` y `cancel()` para descartar la respuesta vieja.
  Diferencia clave con `useForm`: `useForm` hace una visita de Inertia (redirect, props nuevos); `useHttp` devuelve la respuesta y no toca la pagina.

Donde verlo en el codigo: `app/Http/Resources/GenerationResource.php:20`, `resources/js/pages/Dashboard.vue:28`, `resources/js/components/generation/GenerationForm.vue:44`

Para practicar: agregar al historial la cantidad de generaciones del dia y el limite ("3 of 20 today") como prop nuevo con su test en `GenerationPagesTest`.

## Etapa 6: Saldos

Conceptos nuevos:

- Props compartidos perezosos: en `HandleInertiaRequests::share()` el saldo va como closure (`fn () => ...`), asi solo se calcula cuando la respuesta lo incluye, y una recarga parcial puede pedirlo por nombre (`only: ['balance']`).
- `$attributes` en el modelo: los defaults de la columna (balance 0, no admin) tambien en memoria. Sin esto, un `User` recien creado tiene `balance_nano = null` hasta releerlo de la DB.
- `dontReport` para reglas de negocio: un rechazo esperado (sin saldo, limite diario) no es un error del sistema. Se muestra al usuario y no se manda al admin.

Donde verlo en el codigo: `app/Http/Middleware/HandleInertiaRequests.php:46`, `app/Models/User.php:38`, `bootstrap/app.php:32`

Para practicar: agregar un test que verifique que un usuario con saldo negativo ve el aviso en la pagina Credits y no puede generar.

## Etapa 7: Admin, ajustes, conciliacion

Conceptos nuevos:

- Gate + middleware `can:`: `Gate::define('admin', ...)` define el permiso una vez; las rutas lo piden con `->middleware('can:admin')` y los FormRequests lo repiten en `authorize()`. Defensa doble: si alguien agrega una ruta admin fuera del grupo, el request igual la frena.
- `after()` en un FormRequest: validaciones que miran varios campos ya parseados (rango invertido, rango demasiado largo) sin escribir una Rule aparte.
- Agrupar por dia en otra zona horaria en Postgres: `(completed_at AT TIME ZONE 'UTC') AT TIME ZONE ?` para el dia, y el filtro como rango UTC medio abierto para que use el indice.

Donde verlo en el codigo: `app/Providers/AppServiceProvider.php:31`, `app/Http/Requests/Admin/DateRangeRequest.php:35`, `app/Services/ReportService.php:19`

Para practicar: agregar al reporte una columna "fallidas" por dia (generaciones failed, sin cobro) con su test en `ReportServiceTest`.

## Etapa 8: README, datos demo y verificacion final

Conceptos nuevos:
- Comando artisan idempotente: datos demo por el flujo real (servicio, hold, cola) con form tokens fijos, asi correrlo dos veces no duplica.
  Equivalente en Laravel: un seeder, pero el seeder escribe directo en la DB y saltea las reglas de negocio.
- Verificar contra el proveedor real: los errores aleatorios del proveedor (internal_error) no son bugs nuestros; se distinguen mirando el payload del proveedor.
- CA bundle de PHP en Windows: sin curl.cainfo, cualquier HTTPS saliente falla con cURL error 60.

Donde verlo en el codigo: `app/Console/Commands/CreateDemoGenerations.php:49`, `tests/Feature/DemoGenerationsCommandTest.php:36`

Para practicar: que el comando imprima "Already queued" para las muestras que ya existian, con su test.
