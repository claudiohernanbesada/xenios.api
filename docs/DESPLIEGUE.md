# Despliegue y migración

## Preparación

1. Instalar una versión de PHP soportada (mínimo compatible 8.2), extensiones PDO MySQL, curl, OpenSSL, fileinfo, mbstring y GD para los procesos que las utilicen. Apache 2.4 con rewrite, headers y SSL.
2. Publicar este repositorio en un vhost exclusivo de `api.xenios.com.ar`. No apuntarlo al sitio heredado ni compartir sus carpetas operativas.
3. Obtener un certificado válido para ese hostname, comprobar A y AAAA públicos y habilitar renovación automática. Usar TLS 1.2/1.3. Aplicar primero el certificado y luego exigir HTTPS.
4. Completar `.env.example` como `/etc/xenios/api/.env`, legible únicamente por el servicio PHP y administradores (por ejemplo, root:www-data, 0640). Nunca publicar credenciales mediante Git.
5. Configurar un usuario MySQL propio, base correcta y privilegios mínimos. El nombre predeterminado es `xeniosDbX`; confirmar esquema real. Este repositorio no aplica migraciones.
6. Crear `/var/lib/xenios-api/{avatar,personal,authsalida,rate-limit}` y dar escritura al servicio PHP. Mantenerlos fuera del document root; copiar los archivos necesarios con verificación de integridad y acceso.
7. Configurar SMTP con validación de certificado, credenciales Xenios y remitente autorizado. Configurar FCM/APNs de las aplicaciones Xenios y puente 2FA del backend.
8. Programar herramientas exclusivamente por CLI; ver [HERRAMIENTAS.md](HERRAMIENTAS.md).

## Archivo privado de configuración

Antes de publicar este cambio, crear la carpeta y copiar el `.env` vigente desde el checkout del servidor, reemplazando `/ruta/al/checkout` por su ubicación real:

```bash
sudo install -d -o root -g www-data -m 0750 /etc/xenios/api
# Si ya existe el destino, respaldarlo antes de reemplazarlo.
sudo test ! -e /etc/xenios/api/.env
sudo install -o root -g www-data -m 0640 /ruta/al/checkout/.env /etc/xenios/api/.env
sudo -u www-data test -r /etc/xenios/api/.env
```

Ejecutar los comandos de forma individual y detenerse si alguno falla. Ajustar el grupo si PHP utiliza otro usuario. Si `XENIOS_MOVIL_ENV_FILE` está definido en Apache, PHP-FPM o cron, actualizarlo a `/etc/xenios/api/.env` o quitarlo para usar la ruta predeterminada. Verificar `/xenios-movil/health` con la clave vigente y los procesos CLI antes de retirar el archivo anterior del directorio publicado. Conservar cualquier respaldo fuera del sitio y con permisos restringidos.

El cambio quita `.env` del índice Git; el historial anterior sigue conteniendo sus valores y las credenciales expuestas deben rotarse. Al actualizar un checkout que todavía tiene `.env` versionado, copiarlo a la ubicación privada **antes** de aplicar la actualización.

## Apache

El vhost debe tener `ServerName api.xenios.com.ar`, `DocumentRoot` apuntando al checkout publicado y `AllowOverride All` en esa carpeta para aplicar las protecciones incluidas. Activar rewrite, headers y SSL. Desactivar `ServerTokens` detallados a nivel global. Evitar ejecución de scripts desde las carpetas de almacenamiento y acceso directo a ellas mediante alias públicos.

Si existe un proxy TLS, mantener acceso al origen restringido y configurar explícitamente terminación TLS y proxies confiables en el vhost. La regla actual de `.htaccess` presupone TLS directo en Apache; no desactivar su exigencia sin reemplazarla por una protección equivalente.

## Consumidores que deben cambiar

- Base REST: `https://api.xenios.com.ar/xenios-movil`.
- Renombrar variables, funciones de integración, esquema de enlaces de aplicación, package/bundle ID y proyecto de notificaciones a los identificadores Xenios documentados en configuración.
- Actualizar claves y rutas en Xenios.App, aplicación móvil, cron, PowerShell y servicios externos.
- Todas las rutas REST: `X-API-KEY`. Las de integración indicadas en API.md: también `X-Service-Key`, reservado al servidor.
- Descargas `/avatar/`, `/personal/`, `/authsalida/`: `X-Service-Key`; una URL pública insertada en `<img>` ya no basta. El backend debe servirlas mediante un proxy autenticado o usar el endpoint personal autorizado. No exponer la clave de servicio en HTML.
- Puente web: nuevas cabeceras `X-XENIOS-WEB-*` y secreto propio. Coordinar con el controlador consumidor antes de activarlo.
- Adaptar subida de fotos/PDF al nombre aleatorio devuelto por el servidor; persistir ese nombre en la base.
- No incorporar clave de servicio, de firma o credenciales SMTP en la aplicación móvil.
- Confirmar las URLs reales de Google Play/App Store y el deep link del nuevo binario antes de distribuirlo.

## Verificación de aceptación

Comprobar certificado sin omitir validación; `/health` con/sin clave; CORS permitido/prohibido; bloqueo de `.env`, `.git`, herramientas y librerías; HTTP rechazado; descarga directa sin clave denegada incluso si existe el archivo; JPG/PDF válidos e inválidos; límites 429 y almacenamiento de contadores.

Con una base de prueba Xenios: login/MFA, cambio y recuperación de contraseña, permisos de EMPLEADO/seguridad/logística/administración, aislamiento por destinatario/sede, reportes, visitas de varios legajos, QR de salida, circuito completo de notas de traslado y correo real. Probar notificaciones en teléfono físico en primer plano, segundo plano y bloqueado.

## Estado de esta entrega

El 27/10/2026 se aplicó este cambio en `/var/www/vhost/api/www`: `.env` trasladado a `/etc/xenios/api/.env`, fuera del índice Git y del directorio público; permisos root:www-data 0640 y directorio 0750. Respaldo previo en `/var/backups/xenios-api/20261027T202945Z-env-move`, accesible solo por root.

Se verificó igualdad de la configuración efectiva antes/después, lectura como www-data, HTTPS health 200 con clave y 401 sin ella, bloqueo 403 de `.env`, `.git/config` y `_inc/env_loader.php`, MySQL `SELECT 1` como www-data y Apache activo. No se verificaron los circuitos completos de negocio, SMTP, FCM/APNs ni teléfono físico.
