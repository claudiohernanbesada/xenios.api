# API SGI Móvil (WAMP)

**URL base:** `http://api.raei.com.ar/sgi-movil`

## Prueba rápida

- Salud: `GET http://api.raei.com.ar/sgi-movil/health`
- Login: `POST http://api.raei.com.ar/sgi-movil/auth/login`  
  Body JSON: `{ "correo": "...", "clave": "..." }`

## Rutas de archivos (publicación)

Todas las rutas físicas y URLs públicas están en:

`C:\wamp64\www\api.raei.com.ar\_inc\rutas_archivos.php`

Variable global: `$GLOBALS['SGI_RUTAS_ARCHIVOS']`

| Clave | Carpeta local | Uso |
|-------|---------------|-----|
| `avatar_usuario` | `/avatar/` | `Usuarios.ImgPerfil` |
| `foto_personal` | `/personal/` | `Personal.Foto` |
| `auth_salida` | `/authsalida/` | PDF autorización de salida |

Al publicar, editar `url` y `url_respaldo` en ese archivo (ver `rutas_archivos.ejemplo.php`).

Subida desde SGI web (mismos repos que `rutas_archivos.php`):

| Recurso | Script SGI web | Repo |
|---------|----------------|------|
| Avatar perfil (`Usuarios.ImgPerfil`) | `/actions/uploadAvatar/upload.php` | `avatar_usuario` → `/avatar/` |
| Foto legajo (`Personal.Foto`) | `/actions/uploadImgPersonal/upload.php` | `foto_personal` |
| PDF autorización | `/actions/uploadAuthSalida/upload.php` | `auth_salida` |

La **app móvil** usa `POST/DELETE /sgi-movil/auth/avatar` (no sube al vhost api): el endpoint guarda en `dir_absoluta` de `avatar_usuario` (ej. `C:\wamp64\www\avatar.raei.com.ar`, vhost `avatar.raei.com.ar.local`). La carpeta `api.raei.com.ar/avatar/` queda solo como lectura legacy.

## Credenciales MySQL

Editar `C:\wamp64\www\api.raei.com.ar\_inc\credenciales.php`

## Apache

Activar `mod_rewrite`. El archivo `.htaccess` en esta carpeta enruta todo a `index.php`.

## Rol EMPLEADO

En la app web: crear usuario en **Usuarios**, asignar rol **EMPLEADO** en `RolUsuario`, y que el mail coincida con `Personal.Email` o `EmailCorporativo`.

```sql
INSERT INTO Roles (Id, RolName, RolDescription)
VALUES (29, 'EMPLEADO', 'Consulta app móvil')
ON DUPLICATE KEY UPDATE RolDescription = VALUES(RolDescription);
```
