# Pixelforge Studio

English: [README.md](README.md)

App web para generar imagenes con la API de Pixelforge. El usuario ve un rango de precio antes de enviar, paga el costo del proveedor mas un markup (25% por defecto, lo define el admin) con un saldo ficticio y sigue cada generacion en vivo. El admin ve gasto, costo del proveedor y ganancia por dia y por usuario, y lo concilia contra los numeros de uso de Pixelforge.

Hecha con Laravel 13, Vue 3 + Inertia (starter kit oficial de Vue), PostgreSQL, Redis (cache) y una cola en base de datos.

## Contenido

- [Como funciona el dinero](#como-funciona-el-dinero)
- [El recorrido de una generacion](#el-recorrido-de-una-generacion)
- [Entorno local](#entorno-local)
- [Datos demo](#datos-demo)
- [Tests y chequeos](#tests-y-chequeos)
- [Deploy (VPS con Apache)](#deploy-vps-con-apache)
- [Decisiones y limites conocidos](#decisiones-y-limites-conocidos)

## Como funciona el dinero

- **Solo enteros.** Todo monto es un entero de nano-USD (1 USD = 1.000.000.000). Pixelforge informa costos con 9 decimales, asi que es exacto. Las tarifas llegan como strings decimales y se convierten sin floats; una tarifa que no da un nano entero por token se rechaza. El navegador nunca hace cuentas de dinero: el servidor manda strings formateados.
- **Estimado.** Sale de la tabla en vivo de `GET /v1/pricing` (cache de 5 minutos, tarifas documentadas como respaldo): tokens de texto segun el largo del prompt con el +-15% documentado, 765 tokens por imagen de referencia, tokens de salida segun tamano y calidad, y el rango de razonamiento segun calidad. El usuario ve un rango de precio min-max.
- **Markup.** Precio = costo x (100 + markup) / 100, redondeado hacia arriba al nano. El markup vigente se lee una vez al crear la generacion y se guarda en ella; el estimado, la reserva y el cobro final usan ese valor, asi que cambiar el ajuste nunca recalcula una generacion en curso.
- **Reserva.** Al enviar se reserva el precio maximo del saldo (en una transaccion con la fila del usuario bloqueada, junto con el chequeo del limite diario). Si no alcanza, el pedido se rechaza y dice cuanto falta.
- **Liquidacion.** Cuando Pixelforge informa el estado final, se libera la reserva y, si salio bien, se cobra el precio real: `usage` x las `rates` fijadas en esa generacion. Las fallidas no cuestan nada. Liberacion y cobro van en una transaccion, y un unico `(generation_id, type)` en el ledger hace imposible un segundo cobro.
- **Ledger.** `ledger_entries` es solo de agregado (`topup`, `hold`, `release`, `charge`); `users.balance_nano` es su cache y solo lo escribe `LedgerService`. "Buy credits" agrega cargas ficticias (5, 10 o 20 USD), idempotentes por formulario.

## El recorrido de una generacion

1. **Envio.** El request web solo valida, reserva y guarda la generacion como `pending`. Su ULID es tambien la `Idempotency-Key` y `metadata.local_id`. Un form token evita el doble envio.
2. **Despacho.** Un despachador encolado (con lock, FIFO) la manda cuando hay menos de 3 en curso. Antes de cada POST marca el intento como ambiguo. 429 y 503 esperan segun `Retry-After`, el limite diario pausa hasta las 00:00 UTC, un 401 pausa y avisa al admin por mail. Timeouts y 5xx se reintentan con la misma clave. Despues de 6 intentos fallidos o 15 minutos, una generacion ambigua primero se busca por `local_id` en Pixelforge antes de darla por fallida y liberar la reserva.
3. **Estado.** Los webhooks son el camino principal: firma verificada (HMAC-SHA256 de `timestamp.cuerpo crudo`, tolerancia 300 s), cada event id se guarda una vez, los eventos se asocian por id del proveedor o `local_id` (uno puede llegar antes que la respuesta del POST). El estado solo avanza (queued, processing, final) y un estado final del proveedor nunca cambia. El polling cada 10 s es el respaldo para generaciones sin novedades hace 15 s. Un unico limitador (50 llamadas por minuto) cubre toda llamada a Pixelforge.
4. **Historial.** El dashboard consulta nuestra propia base mientras haya algo abierto, muestra precio estimado y real, y deja reintentar las fallidas (una generacion nueva con una reserva nueva). Las URLs de imagen vencidas muestran un placeholder.

Admin (usuarios con `is_admin`, asignado solo por el seeder):

- **Reporte**: cobrado, costo del proveedor y ganancia por dia (en la zona horaria de los ajustes) y por usuario, por fecha de finalizacion.
- **Conciliacion**: nuestro costo del proveedor por dia UTC contra `GET /v1/usage` para la API key actual, comparado exacto, con la cantidad de generaciones. "Sync pending" encola una pasada de polling forzada y el despachador (una vez por minuto).
- **Ajustes**: zona horaria del reporte, limite diario por usuario, registro abierto o cerrado, markup. Los ajustes quedan cacheados en Redis hasta el proximo guardado.

## Entorno local

Requisitos: PHP 8.4 (`pdo_pgsql`, `redis`, `intl`, `mbstring`, `zip`), Composer, Node 22+, PostgreSQL 15+, Redis.

```bash
cp .env.example .env
composer install
npm install
php artisan key:generate
```

Crear las bases `pixelforge` y `pixelforge_testing`, y completar `.env`:

| Variable                                  | Para que                                                         |
| ----------------------------------------- | ---------------------------------------------------------------- |
| `DB_*`                                    | Conexion PostgreSQL (base de desarrollo `pixelforge`)            |
| `REDIS_*`, `CACHE_STORE=redis`            | Cache, limitador compartido de la API, locks de jobs y ajustes   |
| `PIXELFORGE_API_KEY`                      | Clave del proveedor. Usar una distinta por entorno               |
| `PIXELFORGE_WEBHOOK_SECRET`               | Secreto `whsec_...` para verificar las firmas de los webhooks    |
| `PIXELFORGE_BASE_URL`                     | `https://pixelforge.promptedit.dev`                              |
| `ADMIN_EMAIL`                             | Recibe los reportes de excepciones en produccion (necesita SMTP) |
| `SEED_ADMIN_EMAIL`, `SEED_ADMIN_PASSWORD` | Cuenta admin que crea el seeder                                  |
| `SEED_DEMO_EMAIL`, `SEED_DEMO_PASSWORD`   | Cuenta demo que crea el seeder, con 20 USD de credito ficticio   |

```bash
php artisan migrate --seed
composer dev               # servidor web, worker de la cola y Vite
php artisan schedule:work  # en otra terminal: despachador y polling cada 10 s
```

Los webhooks no llegan a `localhost`; en local el estado lo actualiza el polling de respaldo. Para probar webhooks reales, exponer la app con un tunel (Expose, ngrok, Cloudflare Tunnel) y apuntar el webhook de Pixelforge a `<tunel>/webhooks/pixelforge`.

Las "palabras magicas" de Pixelforge en un prompt sirven para probar cada camino: `[fail]`, `[slow]`, `[blocked]`, `[overloaded]`, `[duplicate]`, `[succeed]`.

## Datos demo

```bash
php artisan db:seed          # cuentas admin + demo (se puede correr dos veces)
php artisan pixelforge:demo  # encola 5 generaciones de ejemplo para la cuenta demo
```

`pixelforge:demo` manda los ejemplos por el flujo normal (estimado, reserva, despachador), incluida una `[slow]` y una `[fail]` para mostrar el estado en vivo y el boton de reintento. Correrlo de nuevo no hace nada. Reserva unos 0.70 USD de los 20 USD de credito demo; el cobro real es menor y la fallida es gratis.

## Tests y chequeos

```bash
php artisan test                                        # suite completa (Pest), contra pixelforge_testing
php artisan test --filter=GenerationStateService        # un modulo
vendor/bin/pint --dirty                                 # formato PHP
vendor/bin/phpstan analyse <archivos> --memory-limit=1G # analisis estatico
npm run check && npm run types:check                    # formato, lint y tipos del front
npm run build
```

Los tests nunca usan la base de desarrollo (`phpunit.xml` apunta a `pixelforge_testing`, cache `array`, cola `sync`). La barra de calidad y lo que no se permite para llegar a verde estan en [CONSTRAINTS.es.md](CONSTRAINTS.es.md).

## Deploy (VPS con Apache)

Destino: un VPS con Virtualmin, Apache y PHP-FPM, p. ej. `https://pixelforge.example.com`.

1. **Paquetes del servidor**: PHP 8.4 FPM con `pdo_pgsql`, `redis`, `intl`, `mbstring`, `zip`; PostgreSQL; Redis; Supervisor; Node 22 (solo para el build).
2. **Codigo**:
    ```bash
    git clone <repo> && cd pixelforge
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build      # necesita php 8.4 en el PATH (los helpers de rutas se generan en el build)
    cp .env.example .env && php artisan key:generate
    ```
    En `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, los `PIXELFORGE_*` de produccion, `DB_*`, `REDIS_*`, `MAIL_*`, `ADMIN_EMAIL`, `SEED_*`.
3. **Base de datos y demo**:
    ```bash
    php artisan migrate --force
    php artisan db:seed --force
    php artisan pixelforge:demo
    php artisan config:cache && php artisan route:cache && php artisan view:cache
    ```
4. **Apache**: el document root del virtual host es `public/`, con `AllowOverride All` (el `.htaccess` reescribe a `index.php`) y HTTPS (Let's Encrypt desde Virtualmin). No agregar filtros que reescriban el cuerpo del request: la firma del webhook se verifica sobre el cuerpo crudo.
5. **Worker de la cola** (Supervisor):
    ```ini
    [program:pixelforge-worker]
    command=php /ruta/a/pixelforge/artisan queue:work --sleep=1 --max-time=3600
    user=<usuario del sitio>
    autostart=true
    autorestart=true
    stopwaitsecs=30
    ```
6. **Scheduler** (cron, cada minuto; corre el despachador y el polling de 10 segundos dentro del minuto):
    ```
    * * * * * cd /ruta/a/pixelforge && php artisan schedule:run >> /dev/null 2>&1
    ```
7. **Webhook**: en Pixelforge, apuntar el webhook a `https://<dominio>/webhooks/pixelforge` y poner su secreto en `PIXELFORGE_WEBHOOK_SECRET`.
8. **Despues de cada deploy**: `php artisan migrate --force`, los tres comandos `*:cache` y `php artisan queue:restart`. No correr `cache:clear` si el Redis se comparte con otras apps: vacia la base de cache entera.

## Decisiones y limites conocidos

El registro completo esta en [docs/DECISIONES.md](docs/DECISIONES.md) y el spec en [docs/SPEC-TECNICO.md](docs/SPEC-TECNICO.md). En corto:

- **Dinero en enteros nano-USD**, ledger solo de agregado, reserva del precio maximo. Si las tarifas cambian entre el estimado y la finalizacion, se cobra el precio real aunque supere la reserva; el saldo puede quedar negativo, lo que bloquea nuevas generaciones.
- **Cola propia delante de Pixelforge** (3 en curso, FIFO) para que un usuario nunca vea un 429 del proveedor. Cada generacion se guarda antes del POST y se usa la misma clave de idempotencia en cada reintento; los envios ambiguos se concilian por `local_id` antes de rendirse.
- **Un solo servicio de estado** para respuestas del POST, webhooks, polling y conciliacion, con lock de fila: el estado solo avanza y un estado final del proveedor nunca cambia. Una falla local sigue siendo reversible: si Pixelforge despues informa exito, gana el proveedor y se cobra.
- **Markup guardado por generacion** (como las tarifas del proveedor). El estimado en vivo del formulario puede ser de antes de un cambio de markup; la reserva y el cobro usan el markup leido al enviar.
- **Redis para la cache** (ajustes, limitador compartido de la API, locks de jobs), cola en base de datos. Si Redis cae, las paginas que leen ajustes fallan. Un cambio de ajustes hecho directo en la base no se ve hasta el proximo guardado desde la pagina de admin.
- **La conciliacion es por API key**: las generaciones enviadas con una key anterior no se consultan ni se comparan (una consulta con la key nueva responderia 404).
- **Fuera de alcance**: pagos reales, guardar imagenes despues de que vence su URL, cancelar una generacion, verificacion de mail y recuperacion de contrasena por mail, borrado de cuenta (destruiria registros de dinero), otros idiomas.
