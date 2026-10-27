# Configuración

Fuente recomendada: `/etc/xenios/api.env`, seleccionable por `XENIOS_MOVIL_ENV_FILE`. En desarrollo, `.env` de la raíz. El entorno del proceso prevalece sobre dotenv. `.env.example` es la plantilla sin secretos.

- `XENIOS_DB_*`: host, base, usuario y contraseña MySQL propios; base predeterminada xeniosDbX. No aplica migraciones.
- `XENIOS_API_TOKEN`, `XENIOS_SERVICE_TOKEN`, `XENIOS_JWT_SECRET`, `XENIOS_WEB_BRIDGE_SECRET`: secretos independientes, aleatorios, al menos 32 bytes. Servicio y puente nunca se distribuyen al cliente.
- `XENIOS_2FA_*`: backend interno y clave del puente de doble factor; El código restringe el host a loopback (127.0.0.1, localhost o ::1); para otro servidor usar un túnel local seguro.
- `XENIOS_WEB_BASE_URL`: backend web, confirmar hostname real antes de publicar.
- `XENIOS_CORS_ORIGINS`: orígenes exactos separados por comas, esquema y puerto incluidos.
- `XENIOS_AVATAR_DIR`, `XENIOS_PERSONAL_DIR`, `XENIOS_AUTHSALIDA_DIR`: almacenamiento propio fuera del document root. `XENIOS_RATE_LIMIT_DIR`: contadores compartidos por los procesos del nodo.
- `XENIOS_MAIL_*`, `XENIOS_SMTP_*`: remitente, nombre, logo, servidor, puerto, cifrado y credenciales; comprobar el certificado y remitente autorizado.
- `XENIOS_PASSWORD_RESET_URL`, `XENIOS_APP_LOGIN_URL`: página HTTPS y deep link real del nuevo binario.
- `XENIOS_FCM_*`, `GOOGLE_APPLICATION_CREDENTIALS`: proyecto y JSON privado de Firebase fuera de la publicación.
- `XENIOS_APNS_*`: clave, key ID, team ID y topic/bundle ID del binario iOS.
- `EXPO_PUBLIC_APP_VERSION`, `XENIOS_MOVIL_*_STORE_URL`: versión y enlaces reales de tiendas; confirmar los identificadores del binario Xenios.
- `ASISTENCIA_CALCULO_*`, `MAXIMO_HORAS_EXTRAS_DIA`: máximos de jornada, descanso, fraccionamiento, deduplicación y extras. Son reglas de negocio.
- Variables de alícuotas y herramientas: solo afectan sus integraciones; consultar consumidor en la tabla.

## Variables leídas por PHP

No se leen valores privados para generar esta tabla. Las variables de rutas de almacenamiento se pasan indirectamente por nombre y figuran arriba y en `.env.example`.

| Variable | Consumidores |
|---|---|
| `ASISTENCIA_CALCULO_DEDUPLICACION_MINUTOS` | `xenios-movil/config.php` |
| `ASISTENCIA_CALCULO_DESCANSO_MINIMO_HORAS` | `xenios-movil/config.php` |
| `ASISTENCIA_CALCULO_FRACCIONAMIENTO_MINUTOS` | `xenios-movil/config.php` |
| `ASISTENCIA_CALCULO_JORNADA_VIGILANCIA` | `xenios-movil/config.php` |
| `ASISTENCIA_CALCULO_MAX_DURACION_JORNADA_HORAS` | `xenios-movil/config.php` |
| `CURL_CA_BUNDLE` | `xenios-movil/lib/push_native.php` |
| `GOOGLE_APPLICATION_CREDENTIALS` | `xenios-movil/config.php` |
| `MAXIMO_HORAS_EXTRAS_DIA` | `xenios-movil/config.php` |
| `SSL_CERT_FILE` | `xenios-movil/lib/push_native.php` |
| `XENIOS_2FA_BASE_URL` | `xenios-movil/config.php` |
| `XENIOS_2FA_BRIDGE_TOKEN` | `xenios-movil/config.php` |
| `XENIOS_API_TOKEN` | `_inc/api_tokens.php` |
| `XENIOS_APNS_KEY_FILE` | `xenios-movil/config.php` |
| `XENIOS_APNS_KEY_ID` | `xenios-movil/config.php` |
| `XENIOS_APNS_TEAM_ID` | `xenios-movil/config.php` |
| `XENIOS_APNS_TOPIC` | `xenios-movil/config.php` |
| `XENIOS_APP_LOGIN_URL` | `xenios-movil/config.php` |
| `XENIOS_AUTH_SALIDA_REPORT_LOGO_PATH` | `xenios-movil/lib/auth_salida_daily_report.php` |
| `XENIOS_CORS_ORIGINS` | `xenios-movil/config.php` |
| `XENIOS_DB_HOST` | `_inc/credenciales.php` |
| `XENIOS_DB_NAME` | `_inc/credenciales.php` |
| `XENIOS_DB_PASSWORD` | `_inc/credenciales.php` |
| `XENIOS_DB_USER` | `_inc/credenciales.php` |
| `XENIOS_FCM_PROJECT_ID` | `xenios-movil/config.php` |
| `XENIOS_FCM_SERVICE_ACCOUNT_FILE` | `xenios-movil/config.php` |
| `XENIOS_JWT_SECRET` | `xenios-movil/config.php` |
| `XENIOS_MAIL_DISPLAY_NAME` | `xenios-movil/config.php` |
| `XENIOS_MAIL_FROM` | `xenios-movil/config.php` |
| `XENIOS_MAIL_LOGO_PATH` | `xenios-movil/config.php` |
| `XENIOS_MOVIL_ANDROID_STORE_URL` | `xenios-movil/config.php` |
| `XENIOS_MOVIL_ENV_FILE` | `_inc/env_loader.php`, `xenios-movil/config.php` |
| `XENIOS_MOVIL_IOS_STORE_URL` | `xenios-movil/config.php` |
| `XENIOS_PASSWORD_RESET_URL` | `xenios-movil/config.php` |
| `XENIOS_RATE_LIMIT_DIR` | `_inc/security.php` |
| `XENIOS_SERVICE_TOKEN` | `_inc/security.php` |
| `XENIOS_SMTP_ENCRYPTION` | `xenios-movil/config.php` |
| `XENIOS_SMTP_HOST` | `xenios-movil/config.php` |
| `XENIOS_SMTP_PASSWORD` | `xenios-movil/config.php` |
| `XENIOS_SMTP_PORT` | `xenios-movil/config.php` |
| `XENIOS_SMTP_SSL` | `xenios-movil/config.php` |
| `XENIOS_SMTP_TIMEOUT_SECONDS` | `xenios-movil/config.php` |
| `XENIOS_SMTP_USERNAME` | `xenios-movil/config.php` |
| `XENIOS_WEB_BASE_URL` | `xenios-movil/config.php` |
| `XENIOS_WEB_BRIDGE_SECRET` | `xenios-movil/config.php` |
