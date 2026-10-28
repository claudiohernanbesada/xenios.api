# Archivos de autorización de salida

La API guarda los PDF en `/var/storage/authSalida` (respetar la S mayúscula), fuera del directorio público. Configuración privada en `/etc/xenios/api/.env`: `XENIOS_AUTHSALIDA_DIR=/var/storage/authSalida`. Directorio `www-data:www-data`, modo `0750`; documentos `0640`. No crear un alias de Apache hacia esa carpeta.

- Guardar: `POST /actions/uploadAuthSalida/upload.php`, multipart campo `archivo`. Solo PDF hasta 10 MiB, con validación de extensión, MIME y firma `%PDF-`.
- Con `Accept: application/json`, devuelve `201` y `{"archivo":"nombre-confirmado.pdf"}`. Sin esa cabecera conserva la respuesta de texto de los clientes anteriores.
- Obtener: `GET /authsalida/{archivo}`. También admite `HEAD`. Requiere `X-Service-Key`, al igual que la carga. MIME `application/pdf`, caché `private, no-store`.
- La lectura se limita al directorio privado, rechaza nombres con rutas y enlaces simbólicos y no recurre a otras APIs ni carpetas públicas.

Xenios.App usa `AuthSalidaApiClient` y la conexión central `XeniosApiConnection`. Se eliminan `Utilidades.URL_FILE_AUTH_SALIDA` y `Utilidades.PATH_FILE_AUTH_SALIDA`.

`AuthSalidaController.AuthFile` conserva la sesión, los roles de autorización de salida, el filtro de empresa y la auditoría. Exige token antifalsificación; la vista lo envía en la carga. Solo actualiza `AuthSalida.AuthFile`, fecha y usuario de carga cuando la API confirma un nombre válido. `DescargarArchivo` obtiene el PDF con la clave privada y conserva el nombre descriptivo de descarga. La clave no llega al navegador. La generación de formularios PDF y el circuito de autorización/QR conservan su comportamiento.

El endpoint móvil de archivos conserva su autorización existente y usa el mismo lector privado de PDF mediante `stream_auth_salida_file`.

Publicado el 27/10/2026. Respaldo previo: `/var/backups/xenios-authsalida/20261027T214311Z`. No había documentos ni referencias anteriores para migrar. Pasaron la compilación aislada, 167 controles de PHP/Apache, 21 pruebas de clientes/aislamiento por empresa y 24 verificaciones en producción. Se comprobó la carga/descarga exacta con el cliente .NET, PDF de 9 MiB, límite de 10 MiB, permisos, rechazo de rutas relativas y enlaces simbólicos, HEAD y el lector móvil en la nueva ubicación. También pasó la regresión de avatares y artículos. Los archivos temporales se eliminaron; no se modificaron registros de negocio. Queda pendiente la revisión visual con una sesión real de autorizaciones.
