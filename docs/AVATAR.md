# Avatares de usuario

La API centraliza la carga y descarga del avatar. Almacenamiento privado: `/var/storage/avatar`, configurado por `XENIOS_AVATAR_DIR` en `/etc/xenios/api/.env`. Directorio `www-data:www-data` con modo `0750`. No crear un alias público de Apache hacia esa carpeta.

- Carga: `POST /actions/uploadAvatar/upload.php`, multipart campo `archivo`, JPG/JPEG o PNG hasta 100 KiB. Se verifica el contenido real de la imagen. Requiere `X-Service-Key`.
- Con `Accept: application/json`, devuelve `{"archivo":"nombre-confirmado.png"}`. El nombre lo genera la API y es el que debe persistirse. Sin esa cabecera conserva la respuesta de texto compatible con los clientes anteriores.
- Descarga privada: `GET /avatar/{archivo}`, con `X-Service-Key`.

Xenios.App usa `AvatarApiClient` y la conexión central `XeniosApiConnection`. La carga guarda el nombre confirmado por la API en `Usuarios.ImgPerfil`; cualquier error evita modificar ese valor. La vista del perfil y el menú apuntan a `GET /Profile/Avatar`, que exige sesión, consulta la imagen del usuario actual y obtiene sus bytes desde la API. El navegador no recibe claves ni URLs de servidores anteriores. La imagen predeterminada sigue siendo un recurso local.

Se eliminan las propiedades `Utilidades.URL_PERFIL_USER` y `Utilidades.PATH_PERFIL_USER`. La conexión usa la URL base central y la clave de servicio existentes (`XeniosApi:BaseUrl` y `XeniosApi:ServiceKey`, o sus alternativas admitidas por `XeniosApiConnection`). En producción, guardar la clave únicamente en configuración privada del servicio.

Publicado el 27/10/2026: almacenamiento `/var/storage/avatar` con modo `0750`, configuración de la API en `/etc/xenios/api/.env` y credencial del backend web en `/etc/xenios/app-api.env` (root:root, `0600`), cargada por un drop-in de systemd. Respaldo previo en `/var/backups/xenios-avatar/20261027T212657Z`.

Validación: compilación aislada sobre la versión publicada, 9 pruebas del cliente y 133 controles PHP/Apache. En producción se verificó carga y descarga exacta con el cliente .NET, almacenamiento privado, rechazo sin clave, sesión requerida en el proxy y disponibilidad HTTPS de ambos servicios. No se modificaron avatares de usuarios ni otros datos de negocio; el avatar de prueba se retiró. La edición visual dentro de una sesión real del perfil queda pendiente.
# Eliminación de avatares salientes

`DELETE /avatar/{archivo}` requiere `X-Service-Key`. Responde 204 al eliminar el archivo o si ya no existe, 409 si la base móvil todavía lo referencia y 400 para nombres inválidos, placeholders o enlaces simbólicos. Solo opera dentro del almacenamiento configurado de avatares.

La web comprueba también las referencias de su propia base de datos antes de llamar a la API. El borrado ocurre después de confirmar el perfil. Si falla la persistencia, se descarta el archivo nuevo que no haya quedado asociado a un usuario. Quitar el avatar también elimina el archivo saliente. Si no se puede completar la limpieza, el perfil web informa que los datos se guardaron y muestra una observación.

Los handlers móviles actualizan primero `Usuarios.ImgPerfil` y después eliminan el archivo anterior sin referencias; conservan el anterior si falla la actualización.

Publicado el 27/10/2026 (Argentina). Validación: 114 pruebas .NET, 215 comprobaciones de API, 8 pruebas específicas PHP y 33 comprobaciones en producción. Se verificó físicamente el borrado por reemplazo y por eliminación desde el perfil. Respaldo: `/var/backups/xenios-avatar-cleanup/20261028T011600Z`.

