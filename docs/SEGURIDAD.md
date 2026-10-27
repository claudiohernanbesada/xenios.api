# Seguridad

## Controles implementados

- Credenciales MySQL fuera del código; `.env` excluido del índice Git. La configuración del proceso prevalece sobre dotenv.
- Secretos separados: `XENIOS_API_TOKEN` (acceso REST), `XENIOS_SERVICE_TOKEN` (operaciones privilegiadas), `XENIOS_JWT_SECRET` (sesión), `XENIOS_WEB_BRIDGE_SECRET` (firma del puente).
- Solo encabezados para autenticación: `X-API-KEY`, `X-Service-Key`, `Authorization: Bearer ...` o `X-Auth-Token`. Las claves de acceso y sesiones no se admiten por query string. Los tokens de recuperación sí viajan en el enlace temporal de recuperación.
- CORS con orígenes exactos configurables, sin comodín ni credenciales automáticas. CORS no reemplaza autenticación ni autorización.
- HTTPS obligatorio en Apache para hostnames públicos; solicitudes HTTP rechazadas. La excepción localhost facilita pruebas locales.
- HSTS al servir HTTPS, bloqueo de framing, MIME sniffing y referente; respuestas dinámicas sensibles sin caché.
- Bloqueo HTTP de `.env`, `.git`, `_inc`, `_private`, documentación, herramientas, logs y configuración.
- Subidas autenticadas, nombres aleatorios, validación real JPG/PNG o PDF, límites de tamaño. Apache envía todos los archivos de los repositorios al controlador, incluso si existen físicamente.
- Descargas remotas con TLS verificado, sin URL elegida por el cliente ni seguimiento automático de redirecciones y límite de 10 MB.
- Limitación atómica por IP real: 300 peticiones REST/minuto; 20 peticiones de autenticación/15 minutos y 20 accesos de recuperación/15 minutos. Devuelve 429 con `Retry-After`; si no puede persistir el contador, 503.
- Puente web: HMAC de `usuarioId|timestamp`, vigencia ±300 segundos y secreto independiente del token distribuido a clientes. Las cabeceras son `X-XENIOS-WEB-USER-ID`, `X-XENIOS-WEB-TS`, `X-XENIOS-WEB-SIGNATURE`.

## Límites y trabajo requerido para producción

1. HTTPS público y redirección HTTP a HTTPS verificados el 26/10/2026; el vhost devuelve 404 en la raíz, por lo que falta desplegar y validar la API.
2. Rotar las credenciales heredadas: el historial Git contenía `.env` y una contraseña MySQL. Quitarlas del árbol actual no las elimina del historial ni revoca su acceso. La rotación del servidor y limpieza del historial requieren coordinación con sus consumidores.
3. La contraseña de usuarios continúa en SHA-256 para compatibilidad con el backend web. Migrar coordinadamente a Argon2id/bcrypt y actualizar todos los productores/validadores antes de afirmar que la autenticación está completamente reforzada.
4. El cierre de sesión actual no implementa lista de revocación de tokens firmados. Un token robado puede seguir válido hasta vencer. Implementar sesiones revocables compartidas con el backend es una mejora pendiente.
5. El puente firmado admite repetición durante su ventana temporal. Para eliminarla, coordinar nonce y firma de método/ruta/cuerpo con Xenios.App.
6. El limitador usa disco y una IP por conexión; en múltiples nodos necesita un almacén compartido como Redis. Detrás de proxy debe configurarse `mod_remoteip` con proxies explícitamente confiables. No confiar en `X-Forwarded-For` arbitrario. El disco necesita limpieza de contadores expirados y monitoreo.
7. Validar autorizaciones y aislamiento real con usuarios de distintos roles/sedes. Los cambios locales y pruebas sin base de datos no verifican los datos de producción.
8. Configurar TLS/usuarios mínimos en MySQL cuando la base no sea local, firewall, backups, actualizaciones, observabilidad y permisos de disco.
9. Un PDF válido por cabecera/MIME puede contener contenido activo. Si los archivos provienen de terceros, incorporar análisis antivirus/desarme antes de distribuirlos.
10. Confirmar el hostname público de Xenios.App; el valor predeterminado `app.xenios.com.ar` debe ajustarse a su publicación real.

## Referencias

- [OWASP REST Security](https://cheatsheetseries.owasp.org/cheatsheets/REST_Security_Cheat_Sheet.html).
- [PHP Password Hashing](https://www.php.net/passwords).

Estos controles reducen riesgos concretos; no constituyen una certificación ni reemplazan una revisión de seguridad y pruebas del despliegue.
