# Spec tecnico: Pixelforge Studio

English: [SPEC.md](SPEC.md)

Estado: APROBADO (2026-11-26), actualizado al cierre de la etapa 8
Fuente: [relevamiento/PREGUNTAS-pixelforge.md](relevamiento/PREGUNTAS-pixelforge.md)
Consigna: https://pixelforge.promptedit.dev/assessment
API: https://pixelforge.promptedit.dev/docs (notas de integracion en [API-PIXELFORGE.md](API-PIXELFORGE.md))

## 1. Resumen

Prueba tecnica para PromptEdit. App web donde un usuario con sesion genera imagenes con la API simulada Pixelforge, ve el costo antes de enviar, paga el costo del proveedor + un markup (25% por defecto, lo define el admin) con saldo ficticio y sigue el estado en vivo. Un admin ve gasto, costo y ganancia. Entrega: hoy. Prioridad de la consigna: correccion antes que acabado.

## 2. Alcance (incluido)

- Registro e ingreso con mail y contrasena. Interfaz en ingles.
- Formulario: prompt (max 4000), size, quality, hasta 4 URLs de referencia, estimado en rango.
- Cola propia con aviso al usuario cuando el pedido queda esperando turno.
- Historial con estado en vivo (queued, processing, succeeded, failed), precio estimado y final, imagen.
- Boton de reintento en generaciones fallidas.
- Webhooks firmados + polling de respaldo.
- Saldos, boton ficticio "Buy credits", bloqueo si no alcanza.
- Admin: reporte por dia y por usuario, conciliacion con GET /v1/usage, ajustes globales.
- Deploy en VPS propio, repo publico en GitHub, README, datos de demo.

## 3. Fuera de alcance

Pagos reales, facturacion, descarga o guardado permanente de imagenes, cancelar generaciones, verificacion y recuperacion por mail, 2FA, borrado de cuenta (destruiria registros de dinero), otros idiomas, app movil.

## 4. Supuestos

Confirmados por el usuario: rango de estimado; el usuario ve solo su precio; cola visible; registro abierto con limite diario; reintento manual; ingles; zona horaria como ajuste global; reserva del maximo; ejemplos cargados y margen para generar; cuenta de prueba; entrega hoy.

Asumidos (revisar):

1. Limite diario: 20 generaciones por usuario por dia (UTC), editable en ajustes.
2. Ajustes globales: zona horaria (default UTC), limite diario, registro abierto si/no, markup (default 25%, se guarda en cada generacion; DEC-011).
3. Paquetes de credito ficticio: 5, 10 y 20 USD. Usuario nuevo arranca en 0.
4. Cuenta demo y cuenta admin creadas por seeder; contrasenas desde .env, nunca en el repo.
5. Montos en pantalla en USD con hasta 9 decimales, minimo 2, sin redondear.
6. VPS con Virtualmin + Apache, dominio pixelforge.mazar.com.ar con HTTPS. El usuario hace el deploy, como ultima etapa.
7. PostgreSQL.
8. Reporte de errores por mail a ADMIN_EMAIL si hay SMTP; si no, solo log.
9. Modo aprendizaje en la construccion (APRENDIZAJE-pixelforge.md).

## 5. Stack y por que

- Laravel 13 sobre PHP 8.4 + Vue 3 + Inertia, starter kit oficial de Vue (TypeScript, Tailwind, shadcn-vue). Lo exige la consigna y el kit trae auth.
- PostgreSQL: transacciones, locks de fila, agrupacion por zona horaria.
- Cola con driver database + scheduler. Cache en Redis (DEC-012).
- VPS propio: Apache (vhost + mod_rewrite), PHP-FPM, Supervisor (worker), cron (schedule:run), Let's Encrypt.
- Pest.

## 6. Roles y permisos

| Rol   | Puede                                                                                |
| ----- | ------------------------------------------------------------------------------------ |
| User  | Generar, reintentar sus fallidas, ver su historial y saldo, comprar credito ficticio |
| Admin | Lo de User + reporte, conciliacion, ajustes                                          |

Policy por generacion (un usuario nunca ve las de otro). Admin por columna is_admin, asignada solo por seeder.

## 7. Modelo de datos

Dinero: BIGINT en nano-USD (1 USD = 1_000_000_000). Sin floats en ningun punto, tampoco en el front (el servidor manda strings formateados).

- users: is_admin, balance_nano (cache del ledger)
- generations: id ULID (tambien Idempotency-Key y metadata.local_id), user_id, form_token (unico), retry_of_id (unico, nullable), provider_id (unico, nullable), api_key_fingerprint, request_payload (json, se reenvia identico), status, failure_source (provider | local), error_code, error_message, rates (json, del proveedor), markup_percent, usage (json), estimate_min_nano, estimate_max_nano, cost_nano, price_nano, image_url, image_url_expires_at, attempts, ambiguous (bool), attempt_started_at, next_attempt_at, last_synced_at, provider_created_at, completed_at
- ledger_entries: user_id, generation_id (nullable), type (topup, hold, release, charge), amount_nano con signo. Unico (generation_id, type). Solo se agrega
- webhook_events: event_id (unico), type, generation_id (nullable), payload, delivery_attempt, received_at
- settings: key, value

## 8. Modulos

**Pricing** (PHP puro, con tests primero en los casos de la doc)

- Estimado: texto t = ceil(caracteres Unicode / 4), minimo floor(t x 0.85), maximo ceil(t x 1.15); imagen 765 por referencia; salida segun tabla; razonamiento min-max por calidad. Precio = costo x (100 + markup) / 100, con el markup guardado en la generacion (DEC-011).
- Tarifas del estimado: GET /v1/pricing con cache de 5 min y valores de respaldo en config.
- Costo final: usage x rates de esa generacion. Tarifas decimales se convierten desde string a nano-USD por token; si no da entero, excepcion. Si el markup no da un nano entero, redondeo hacia arriba al nano.
- Caso de control: high, 1536x1024, 400 caracteres, 2 referencias: costo maximo 0.373875, precio maximo (25%) 0.46734375.

**Alta de generacion**

- Transaccion con lock del usuario: valida limite diario y saldo >= precio maximo, crea la generacion, registra hold. form_token evita doble envio.
- Sin turno libre: mensaje "Your request is in the queue".

**Despachador** (job con lock; se dispara al crear, al terminar una y por scheduler)

- FIFO por next_attempt_at mientras haya menos de 3 aceptadas sin estado final. Las ambiguas no cuentan ni traban la cabeza.
- Antes del POST guarda attempt_started_at y ambiguous = true (commit). Solo se vuelve a false con una respuesta de rechazo clara y sin intentos ambiguos previos. Un worker que muere deja la marca.

| Respuesta                     | Accion                                                            |
| ----------------------------- | ----------------------------------------------------------------- |
| 202                           | Aplicar snapshot                                                  |
| 429 concurrency / rate        | Vuelve a la cola segun Retry-After; no suma al maximo de intentos |
| 429 daily_limit               | Pausa del despachador hasta el proximo dia UTC                    |
| 503 overloaded                | Backoff segun Retry-After                                         |
| timeout, corte, 500, 502, 504 | Ambigua: reintento con la misma clave y backoff                   |
| 400, 422                      | Sin ambiguedad previa: fallida local. Con ambiguedad: reconciliar |
| 401                           | Pausar despachador y avisar al admin. No se falla nada            |
| 409 idempotency_key_reused    | Reconciliar y avisar                                              |

- Tope: 6 intentos fallidos o 15 minutos. Si hubo ambiguedad, antes de fallar se reconcilia: GET /v1/generations buscando metadata.local_id (paginando hasta la fecha de creacion). Encontrada: se adopta. No encontrada: fallida local, libera reserva. Si la consulta falla, no se decide: se reintenta.

**Estado** (un unico servicio para POST, webhook, polling y reconciliacion)

- Lock de fila. queued < processing < final; nunca retrocede.
- succeeded y failed informados por el proveedor son inmutables.
- failed local es provisorio frente al proveedor: si despues llega un succeeded del mismo local_id, gana el proveedor, se cobra y se registra el aviso.
- Liquidacion en la misma transaccion: release de la reserva y, si salio bien, charge del precio real. Fallida: cobro 0. El unico del ledger impide cobrar dos veces.
- Si el precio real supera la reserva (cambio de tarifas), se cobra costo + markup igual; el saldo puede quedar negativo, bloquea nuevas generaciones y se avisa.

**Webhooks**

- POST sin CSRF. HMAC-SHA256 de "timestamp.cuerpo_crudo", comparacion en tiempo constante, tolerancia 300 s (cada reintento viene firmado con hora nueva). Firma invalida: 400.
- Misma transaccion: insertar event_id (unico) y aplicar estado. Duplicado: 204 sin efecto. Error al aplicar: rollback y 5xx para que reintente.
- Busqueda por provider_id o metadata.local_id (puede llegar antes que la respuesta del POST).
- ping: registrar y 204. Sin generacion asociada: registrar, avisar en log y 204.

**Polling de respaldo**

- Scheduler cada 10 s: aceptadas sin estado final y sin novedades hace 15 s o mas, enviadas con la API key actual.
- Limitador unico para POST, GET y reconciliacion: 50 por minuto, respeta Retry-After y X-RateLimit-Remaining.

**Reintento**

- Solo sobre fallidas (en una local, ya reconciliada). Crea generacion nueva con ULID, clave y reserva nuevas, re-estimada con las tarifas y el markup actuales. retry_of_id unico: un doble clic no duplica.

**Historial** - Lista paginada; la UI refresca contra nuestra base (usePoll) mientras haya estados no finales. Imagen vencida: placeholder "Image expired".

**Saldos** - Balance, paquetes ficticios (topup, idempotente por formulario), bloqueo con mensaje que dice cuanto falta.

**Admin**

- Reporte por dia (zona de ajustes) y por usuario: cobrado, costo proveedor, ganancia, agrupado por completed_at.
- Conciliacion: siempre dias UTC, solo la API key actual, comparacion exacta de costo y cantidad contra /v1/usage, diferencia visible, boton "Sync pending" (pasada de polling forzada + despachador, una vez por minuto).
- Ajustes globales, cacheados en Redis hasta el proximo guardado.

**Datos demo** - `php artisan pixelforge:demo` encola 5 generaciones de ejemplo para la cuenta demo por el flujo normal, incluida una `[slow]` y una `[fail]`. Form tokens fijos: correrlo de nuevo no crea nada.

## 9. Integraciones externas

Pixelforge /v1: pricing, generations (POST, GET, lista), usage, webhooks. Timeout 10 s. Variables: PIXELFORGE_API_KEY, PIXELFORGE_WEBHOOK_SECRET, PIXELFORGE_BASE_URL. Una clave para local y otra para produccion; los limites son por cuenta y se comparten.

## 9b. Manejo de errores

- Excepciones no controladas: mail a ADMIN_EMAIL (no en local ni testing, sin claves ni secretos, con limite de frecuencia).
- Excepciones de negocio: InsufficientBalance, DailyLimitReached, GenerationNotRetryable, InvalidWebhookSignature, ProviderRejected, ProviderUnavailable, UnsupportedRate, InvalidMarkupSetting.
- Al usuario: mensaje claro segun error_code. Detalle tecnico solo en logs.

## 10. Decisiones de arquitectura

Registro completo: [DECISIONES.md](DECISIONES.md).

| Decision                                  | Descartada                          | Por que                                                  |
| ----------------------------------------- | ----------------------------------- | -------------------------------------------------------- |
| Enteros nano-USD                          | Centavos / DECIMAL con float en PHP | Exacto y coincide con los 9 decimales de usage           |
| Cola propia y envio asincrono             | POST dentro del request web         | Un solo camino para limites, reintentos y cola visible   |
| Ledger solo de agregado + saldo cacheado  | Solo columna de saldo               | Auditable, el unico impide doble cobro                   |
| Persistir antes de llamar + idempotencia  | Crear registro al recibir 202       | El webhook puede llegar antes; un corte no pierde cobros |
| Un servicio de estado                     | Logica separada por origen          | Mismas reglas para duplicados y desorden                 |
| Webhook procesado en el request           | Encolarlo                           | Es rapido y el 2xx significa aplicado                    |
| Cola database                             | Cola en Redis                       | Menos infraestructura                                    |
| Markup guardado por generacion            | Markup vigente al liquidar          | El cobro siempre coincide con lo reservado (DEC-011)     |
| Cache en Redis, settings hasta guardarlos | Cache en database                   | Los settings se leen en casi cada request (DEC-012)      |

Duda adversarial (una ronda, contexto limpio): 5 objeciones (2 altas), las 5 aceptadas e incorporadas: salida para intentos ambiguos, marca de intento antes del POST y falla local reversible, cobro por encima de la reserva, definicion del reintento, conciliacion por API key y limitador unico.

Riesgos conocidos: el entorno local consume los mismos limites que produccion; si Pixelforge acepta un pedido despues de una reconciliacion negativa, se corrige recien cuando llega su webhook (cubierto por la falla local reversible); sin webhook, queda visible en la conciliacion.

## 11. Convenciones

C12: FormRequest valida y autoriza (o Policy), Service con la logica, controlador fino.

- app/Services/Pixelforge/PixelforgeClient
- app/Services/{PricingService, GenerationService, DispatchService, GenerationStateService, LedgerService, WebhookService, ReportService, PollingService}
- app/Support/Money
- app/Jobs/{DispatchPendingGenerations, SyncOpenGenerations}
- app/Console/Commands/CreateDemoGenerations
- resources/js/pages/{generations, credits, admin}

Codigo, commits y README en ingles. Docs en ingles con version en espanol.

## 11a. Diseno

- Idea central: cada generacion muestra un "ticket" de costo: rango estimado, reservado, precio final.
- Colores: fondo #F7F8FA, texto #14161F, accion #2B44D9, exito #17805A, espera #A86400, error #B3261E.
- Tipografia: IBM Plex Sans, numeros tabulares en montos.
- Layout: formulario y estimado arriba, historial debajo; una columna en celular.
- Movimiento minimo: cambios de estado y feedback de botones; respetar prefers-reduced-motion. Estados con texto, no solo color.

## 11b. Barra de calidad

Los comandos vigentes estan en [CONSTRAINTS.es.md](../CONSTRAINTS.es.md).

| Chequeo              | Comando                                                 | Cuando                            |
| -------------------- | ------------------------------------------------------- | --------------------------------- |
| Formato PHP          | vendor/bin/pint --dirty                                 | Cada cambio                       |
| Formato y lint front | npm run check                                           | Cada cambio                       |
| Tipos front          | npm run types:check                                     | Cada cambio en resources/js       |
| Analisis estatico    | vendor/bin/phpstan analyse <archivos> --memory-limit=1G | Cada cambio                       |
| Test del modulo      | php artisan test --filter=<Modulo>                      | Cada cambio                       |
| Suite                | php artisan test                                        | Cierre de etapa y antes de deploy |
| Build                | npm run build                                           | Antes de deploy                   |

0 errores en archivos tocados; sin dependencias nuevas sin OK; prohibido silenciar chequeos o saltear tests.
Tests obligatorios: calculo de precios, liquidacion unica, firma, duplicados, desorden, webhook antes que POST, resultado ambiguo, saldo insuficiente, doble envio.

## 12. Plan de etapas

1. [hecha] Scaffold, auth, PostgreSQL, constraints.
2. [hecha] Money + Pricing + tests.
3. [hecha] Cliente, alta, despachador, estado, ledger.
4. [hecha] Webhooks + polling + reconciliacion.
5. [hecha] Formulario e historial.
6. [hecha] Saldos.
7. [hecha] Admin, ajustes, conciliacion, markup editable, cache en Redis.
8. [hecha] README, datos demo, verificacion final local contra la API real (camino de polling).
9. [pendiente] Deploy en el VPS (lo hace el usuario con la guia del README) y prueba de webhooks reales. Entrega.

## 13. Preguntas abiertas

1. ~~Cuenta de Pixelforge y API keys~~ Respondido: creadas; la key local se uso en la verificacion de la etapa 8.
2. ~~VPS~~ Respondido: Virtualmin + Apache, dominio pixelforge.mazar.com.ar, deploy lo hace el usuario, al final.
3. Hay SMTP para avisos de error? Sin SMTP, los reportes solo van al log.
4. ~~Repo~~ Respondido: github.com/matiazar/pixelforge, privado durante el desarrollo, publico para la entrega.

## 14. Estimacion

Rangos con desarrollo asistido por agente, incluyendo tu revision. Poco margen de precision: es una estimacion a priori.

| Modulo / tarea                            | Horas         | Supuesto que mas la mueve                    |
| ----------------------------------------- | ------------- | -------------------------------------------- |
| Setup, auth, constraints                  | 0.5 - 1       | PHP y Node listos en local                   |
| Dinero y estimado                         | 1 - 1.5       | Tarifas como en la doc                       |
| Alta, despachador, estado, ledger         | 2 - 3         | Casos ambiguos y concurrencia (mayor riesgo) |
| Webhooks, polling, reconciliacion         | 0.75 - 1      | Pruebas contra la API real                   |
| Formulario e historial                    | 1 - 1.5       | Poco pulido visual                           |
| Deploy VPS                                | 0.75 - 2      | Estado actual del servidor                   |
| **Obligatorio**                           | **6 - 10**    |                                              |
| Saldos (UI y bloqueo; ledger ya incluido) | 0.75 - 1.5    |                                              |
| Admin, ajustes, conciliacion              | 1.5 - 2.5     | Zonas horarias                               |
| QA final, README, demo, entrega           | 0.75 - 1.5    | Ajustes tras probar en produccion            |
| **Total sin aprendizaje**                 | **9 - 15.5**  |                                              |
| Modo aprendizaje                          | 1 - 2         | Profundidad de las explicaciones             |
| **Total**                                 | **10 - 17.5** |                                              |

## Historial de cambios

- 2026-11-26: primera version.
- 2026-11-26: aprobado. Servidor web del VPS: Apache (no Nginx).
- 2026-11-26: etapa 3: estado local `pending`; form_token unico por usuario; cuota diaria agotada falla las pendientes no ambiguas en el acto; despachador con maximo 5 intentos por corrida. Detalle de la API en API-PIXELFORGE.md.
- 2026-11-26: deploy movido a la ultima etapa (pedido del usuario); VPS Virtualmin, dominio pixelforge.mazar.com.ar; repo privado.
- 2026-11-26: etapa 5: reintento re-estima con tarifas actuales; seeder de cuentas adelantado; estimado con throttle 120/min. Prueba de palabras magicas pasa a la etapa 6 (hace falta saldo).
- 2026-11-26: etapa 6: pagina Credits; borrar cuenta sacado (pedido del usuario, ya estaba fuera de alcance); rechazos de negocio sin reporte al admin.
- 2026-11-26: etapa 7: area admin (reporte, conciliacion, ajustes); conciliacion asume dia UTC de completed_at; Sync pending encola el despachador; registro cerrado bloquea /register.
- 2026-11-26: fixes de la etapa 7: markup editable por el admin y guardado por generacion (DEC-011); cache en Redis con settings cacheados hasta guardarlos (DEC-012); polling solo para la API key actual; conciliacion compara costo y cantidad.
- 2026-11-26: etapa 8: comando `pixelforge:demo`, README reescrito, verificacion final local. Docs en ingles con version en espanol.
