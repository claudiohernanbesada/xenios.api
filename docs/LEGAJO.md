# Documentos del legajo

Almacenamiento privado: `/var/storage/legajo`. Estos endpoints guardan y recuperan archivos; la asociación del nombre con `PersonalLegajo` y los permisos del usuario corresponden al backend consumidor. No crean registros en MySQL.

## Configuración del servidor

En `/etc/xenios/api/.env`:

```dotenv
PATH_LEGAJO_PERSONAL=/var/storage/legajo
URL_LEGAJO_PERSONAL=https://api.xenios.com.ar/legajo
```

Estos son también los valores predeterminados. Directorio `www-data:www-data`, modo `0750`; documentos `0640`. Sin alias de Apache hacia el almacenamiento.

## Guardar

`POST https://api.xenios.com.ar/actions/uploadLegajo/upload.php`

Cabecera obligatoria: `X-Service-Key`, con el valor privado de `XENIOS_SERVICE_TOKEN`. Cuerpo `multipart/form-data`, campo `archivo`. Se admiten PDF, JPG/JPEG, PNG y GIF, hasta 100 MiB por archivo. Se valida el contenido y su correspondencia con la extensión. Las imágenes se limitan a 20 millones de píxeles.

Respuesta `201`:

```json
{
  "archivo": "19b1432a-35db-4525-aab5-ab392dcc394c.pdf",
  "url": "https://api.xenios.com.ar/legajo/19b1432a-35db-4525-aab5-ab392dcc394c.pdf",
  "tamano": 24576,
  "tipo": "application/pdf"
}
```

Se conserva el nombre si tiene formato GUID y una extensión permitida (compatible con el consumidor actual); para otros nombres se genera uno aleatorio. El consumidor debe persistir `archivo` de la respuesta. Un nombre existente devuelve `409` y no se sobrescribe. La publicación del documento es atómica.

## Obtener

`GET https://api.xenios.com.ar/legajo/{archivo}`

Requiere la misma cabecera `X-Service-Key`. Devuelve los bytes originales con el MIME correspondiente, `Content-Disposition: attachment` y `Cache-Control: private, no-store`. También admite `HEAD`. Un documento inexistente devuelve `404`; nombres inválidos o rutas relativas devuelven `400`. No se siguen enlaces simbólicos ni se buscan archivos dentro del sitio.

## Consumo desde Xenios.App

Los nombres de las propiedades existentes de `Utilidades` tienen un significado diferente del archivo de entorno de PHP:

| Propiedad de Xenios.App | Valor |
| --- | --- |
| `URL_LEGAJO_PERSONAL` | `https://api.xenios.com.ar/legajo/` |
| `PATH_LEGAJO_PERSONAL` | `https://api.xenios.com.ar/actions/uploadLegajo/upload.php` |

El `PATH_LEGAJO_PERSONAL` del entorno de PHP es una ruta de disco; la propiedad homónima de C# es la URL de carga. El backend debe añadir la clave de servicio tanto al subir como al descargar, comprobar `IsSuccessStatusCode` y actuar como proxy autorizado para los enlaces del navegador. La clave no debe enviarse al navegador ni incluirse en una URL. Esta entrega implementa los endpoints de la API; no modifica Xenios.App.

Errores: `400` archivo/nombre inválido, `401` clave ausente o inválida, `405` método no permitido, `409` nombre existente, `413` límite excedido, `429` límite de cargas y `500` error de almacenamiento. Algunos límites del servidor pueden rechazar la carga antes de PHP.

## Verificación del despliegue

Publicado el 27/10/2026. Pasaron 130 verificaciones locales y 32 controles en el servidor: carga/descarga HTTPS de PDF y PNG, PDF de 12 MiB, HEAD, conservación del GUID, autenticación, rechazo de sobrescrituras, archivos falsos, rutas relativas y enlaces simbólicos. Los archivos de prueba se eliminaron. Respaldo de configuración anterior: `/var/backups/xenios-api/20261027T205731Z-legajo`, accesible solo por root. No se modificaron registros de negocio ni Xenios.App.
