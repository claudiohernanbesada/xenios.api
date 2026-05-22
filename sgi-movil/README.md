# API SGI Móvil

**URL base (producción):** `https://api.raei.com.ar/sgi-movil`

## Prueba rápida

- Salud: `GET https://api.raei.com.ar/sgi-movil/health`
- Login: `POST https://api.raei.com.ar/sgi-movil/auth/login`  
  Body JSON: `{ "correo": "...", "clave": "..." }`

## Rutas de archivos

Todas las rutas físicas y URLs públicas están en `_inc/rutas_archivos.php` del sitio **api.raei.com.ar**:

| Clave | Carpeta en el servidor | Uso |
|-------|------------------------|-----|
| `avatar_usuario` | `/avatar/` | `Usuarios.ImgPerfil` |
| `foto_personal` | `/personal/` | `Personal.Foto` |
| `auth_salida` | `/authsalida/` | PDF autorización de salida |

Subida desde SGI web y app móvil usan el **mismo disco** vía esa configuración:

| Cliente | Avatar (`/avatar/`) |
|---------|---------------------|
| SGI web | `POST /actions/uploadAvatar/upload.php` |
| App móvil | `POST /sgi-movil/auth/avatar` → `sgi_ruta_fisica_escritura('avatar_usuario')` |

## Credenciales MySQL

`_inc/credenciales.php` en el servidor api.raei.com.ar.

## Apache

`mod_rewrite` activo. `.htaccess` en esta carpeta enruta a `index.php`.

## Rol EMPLEADO

```sql
INSERT INTO Roles (Id, RolName, RolDescription)
VALUES (29, 'EMPLEADO', 'Consulta app móvil')
ON DUPLICATE KEY UPDATE RolDescription = VALUES(RolDescription);
```
