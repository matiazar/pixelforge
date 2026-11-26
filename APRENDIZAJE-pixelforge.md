# Aprendizaje: Pixelforge Studio

## Etapa 1: Scaffold, auth, PostgreSQL, constraints

Conceptos nuevos:

- Reporte de errores en Laravel 11+: ya no existe `app/Exceptions/Handler.php`; se configura en `bootstrap/app.php` con `withExceptions()`. `$exceptions->report(fn)` agrega un callback sin cortar el log por defecto (solo lo corta si devuelve `false`).
  Diferencia clave: Laravel ya ignora el ruido (validacion, 404, 403, auth) antes de llamar al callback, asi que no hace falta un `dontReport` para eso.
- No filtrar datos del request en un reporte: `request()->route()->uri()` devuelve el patron (`reset-password/{token}`), no la URL real. Asi el token y el query string no salen del servidor.
- Throttle que no pierde avisos: el throttle se marca despues de encolar el mail (`Cache::has` + `Cache::put`), no antes. Si el envio falla, el siguiente error igual se reporta.

Donde verlo en el codigo: `app/Support/ErrorReporter.php:20`, `bootstrap/app.php:28`

Para practicar: agregar a `ErrorReporter` un test que verifique que el subject del mail incluye el entorno (`production: RuntimeException`).
