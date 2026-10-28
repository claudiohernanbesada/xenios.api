# Imágenes de artículos

Almacenamiento privado: `/var/storage/articulos`, configurado en `/etc/xenios/api/.env` mediante `XENIOS_ARTICULOS_DIR`. Directorio `www-data:www-data`, modo `0750`; imágenes `0640`. No crear un alias público hacia esa carpeta.

| Operación | Endpoint |
| --- | --- |
| Guardar | `POST /actions/uploadImgArticulos/upload.php` |
| Obtener | `GET /articulos/{archivo}` |

Ambos requieren `X-Service-Key` con la clave de servicio privada. La carga usa multipart, campo `archivo`, y admite JPG/JPEG o PNG hasta 512 KiB y 20 millones de píxeles. Se valida el contenido real y se genera un nombre aleatorio. Responde `201` con `{"archivo":"nombre.png","url":"https://api.xenios.com.ar/articulos/nombre.png"}`. El consumidor debe guardar `archivo` en `Articulos.ImgArticulo`.

La descarga también admite `HEAD`, devuelve el MIME de la imagen y `Cache-Control: private, no-store`. Se rechazan nombres con rutas relativas y enlaces simbólicos. No se consultan otras APIs ni carpetas públicas como respaldo.

Xenios.App usa `ArticuloImageApiClient`, que comparte transporte y validaciones con el cliente de avatares mediante `PrivateImageApiClient`. La carga persiste el nombre confirmado por la API. Ante un error, no reemplaza la referencia anterior. Se eliminan `Utilidades.PATH_PANOL_ARTICULO` y `Utilidades.URL_PANOL_ARTICULO`.

Las vistas de alta/edición y los listados de artículos, stock, inventarios y aprobaciones usan `GET /Inventario/Articulos/Imagen/{id}`. Este proxy requiere sesión y rol `SysAdmin`, `ADMIN`, `INVENTARIO_RW`, `INVENTARIO_R` o `APROBACION_RW`; consulta el artículo con el filtro de empresa existente antes de acceder a la API. El navegador no recibe la clave de servicio ni elige el nombre del archivo remoto. Sin imagen se mantiene el recurso local predeterminado.

La clave de servicio y la URL central de Xenios.Api ya se cargan en el backend web desde su configuración privada. No se agregan URLs ni claves a `Utilidades`.

Publicado el 27/10/2026. Respaldo previo: `/var/backups/xenios-articulos/20261027T213459Z`. No había referencias ni imágenes anteriores de artículos para migrar. Pasaron la compilación aislada, 150 controles PHP/Apache, 12 pruebas del cliente/aislamiento por empresa y 21 verificaciones en producción, incluida la carga y descarga exacta mediante el cliente .NET, el límite de 512 KiB, rechazo de accesos sin clave, rutas relativas y enlaces simbólicos, HEAD y continuidad del avatar. Los archivos temporales de prueba fueron retirados. No se modificaron registros de artículos. Queda pendiente la revisión visual dentro de una sesión real de inventario.
