# Documentos administrativos

| Repositorio | Carga multipart (`archivo`) | Lectura | Almacenamiento |
| --- | --- | --- | --- |
| Comprobantes | `POST /actions/uploadComprobantes/upload.php` | `GET /comprobantes/{archivo}` | `/var/storage/comprobantes` |
| Facturas | `POST /actions/uploadFacturas/upload.php` | `GET /facturas/{archivo}` | `/var/storage/facturas` |
| Órdenes de pago y retenciones | `POST /actions/uploadOrdenPagoRetenciones/upload.php` | `GET /ordenPago/{archivo}` | `/var/storage/ordenPago` |

Los endpoints requieren `X-Service-Key` y `X-Xenios-Empresa-Id` (GUID de la empresa). La clave permanece en el backend. Se conserva el límite de 25 MiB por archivo. Formatos permitidos: PDF, JPG/JPEG, PNG, GIF, WEBP, TXT, CSV, XML, DOC/DOCX y XLS/XLSX. Se valida contenido/MIME; DOCX/XLSX deben ser contenedores de Office con las entradas esperadas. No se admiten ejecutables, HTML ni SVG.

La carga responde `201` y JSON con `archivo`, `tamano` y `tipo`. El nombre es aleatorio y contiene la empresa codificada; mide como máximo 50 caracteres, compatible con las columnas actuales. Se debe persistir el nombre confirmado por la API. No hace falta modificar el esquema.

La lectura admite `HEAD`, solo busca en su repositorio privado y comprueba la empresa del nombre. Devuelve `private, no-store`, `nosniff` y disposición de descarga. Se rechazan rutas relativas y enlaces simbólicos. Los directorios tienen propietario www-data:www-data y modo 0750; los archivos, 0640.

La configuración se guarda en `/etc/xenios/api/.env`: `XENIOS_COMPROBANTES_DIR`, `XENIOS_FACTURAS_DIR` y `XENIOS_ORDEN_PAGO_DIR`. No crear alias públicos de Apache hacia esos directorios.

Xenios.App utiliza `DocumentosAdministracionApiClient`. Las vistas, previsualizaciones, respuestas JSON y adjuntos internos consultan la API mediante ese cliente. El navegador accede a `/Documentos/{repositorio}/{archivo}`, que exige sesión, permisos administrativos y empresa propietaria; SysAdmin conserva acceso global de lectura. PDF e imágenes se muestran desde ese proxy; otros formatos se descargan. No se exponen claves ni se consultan las APIs anteriores.

Se eliminan `URL_FILE_COMPROBANTES`, `PATH_FILE_COMPROBANTES`, `URL_FILE_FACTURAS`, `PATH_FILE_FACTURAS`, `URL_FILE_ORDEN_PAGO_RETENCIONES` y `PATH_FILE_ORDEN_PAGO_RETENCIONES`.

## Validación y publicación

Publicado el 27/10/2026 (Argentina). Pasaron 215 comprobaciones locales de seguridad PHP, 35 pruebas .NET de clientes y entregas, y 61 comprobaciones en producción. Se verificaron carga y descarga exactas por HTTPS, aislamiento entre empresas, permisos, límite de tamaño, rechazo de documentos falsos y enlaces simbólicos, protección de los proxies y disponibilidad del servicio. Los ocho archivos temporales de prueba se eliminaron.

Respaldo previo de los archivos reemplazados y la configuración privada: `/var/backups/xenios-documentos/20261028T000822Z`. No hubo cambios de esquema. La validación de producción no incluyó una sesión visual autenticada en el navegador.
