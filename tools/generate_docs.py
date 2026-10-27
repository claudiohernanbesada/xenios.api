"""Regenerate reference indexes without reading any private environment values."""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
DOCS = ROOT / 'docs'
functions = {}
for source in [ROOT / 'xenios-movil/index.php', *sorted((ROOT / 'xenios-movil/lib').glob('*.php'))]:
    text = source.read_text(encoding='utf-8-sig')
    matches = list(re.finditer(r'^function\s+(\w+)\s*\(.*$', text, re.M))
    for i, match in enumerate(matches):
        end = matches[i + 1].start() if i + 1 < len(matches) else len(text)
        functions[match[1]] = (source, text.count('\n', 0, match.start()) + 1, text[match.start():end])

router = (ROOT / 'xenios-movil/index.php').read_text(encoding='utf-8')
routes = [('GET', '/health', '')]
routes += [m.groups() for m in re.finditer(r"\$method === '(GET|POST|DELETE)' && \$path === '([^']+)' => (\w+)\(", router)]
routes += [('GET', '/autorizaciones-salida/{id}/archivo', 'handle_autorizacion_archivo'),
           ('GET', '/novedades/{id}', 'handle_novedades_routes'),
           ('POST', '/novedades/{id}/leida', 'handle_novedades_routes')]
transfer = (ROOT / 'xenios-movil/lib/notas_traslado.php').read_text(encoding='utf-8')
for match in re.finditer(r"if \(\$method === '(GET|POST)' && (.*?)\) \{\s*(\w+)\(", transfer):
    plain = re.search(r"\$path === '([^']+)'", match[2])
    pattern = re.search(r"#\^([^#]+)\$#", match[2])
    path = plain[1] if plain else pattern[1].replace('([^/]+)', '{id}') if pattern else None
    if path:
        routes.append((match[1], path, match[3]))
for method, path in [('GET', ''), ('POST', ''), ('GET', '/opciones'), ('GET', '/personal'),
                     ('GET', '/presentes'), ('GET', '/notificaciones'), ('POST', '/leida'), ('GET', '/historial/{id}')]:
    routes.append((method, '/inout-seguridad' + path, 'handle_inout_seguridad'))
routes = list(dict.fromkeys(routes))

def access(method, path):
    if path.startswith('/reportes/') or path in ['/usuarios', '/areas', '/push/fichadas/procesar', '/horas-extras/motivos-pendientes/enviar-mail'] or (method == 'POST' and path == '/novedades'):
        return 'S'
    if path in ['/health', '/auth/login', '/auth/doble-factor'] or path.startswith('/auth/recuperar-clave'):
        return 'A'
    if path.startswith('/notas-traslado') or (path.startswith('/inout-seguridad/') and any(x in path for x in ['notificaciones', 'leida', 'historial'])):
        return 'B'
    return 'U'

output = '''# Referencia REST

Base: `https://api.xenios.com.ar/xenios-movil`. Todas las rutas requieren `X-API-KEY`; OPTIONS es preflight. JSON UTF-8 salvo multipart de imágenes y descarga de archivos. Fechas: YYYY-MM-DD salvo año/mes separados.

## Credenciales

- A: clave REST, sin sesión previa.
- U: A y `Authorization: Bearer <sesion>` o `X-Auth-Token`, usuario activo, roles y propiedad.
- S: A y `X-Service-Key`, exclusivamente entre servidores.
- B: A y sesión de usuario o puente web firmado. Consultar SEGURIDAD.md.

Estados comunes: 200 operación, 201 creación, 204 preflight, 400 datos, 401 credenciales, 403 permiso, 404 objeto/ruta, 405 método, 429 frecuencia, 500 interno, 503 dependencia/configuración. Los errores del router contienen `message`; el perímetro puede responder texto en 401/429/503. Ver expresiones de respuesta de cada handler para sus estados específicos.

## Rutas

| Método | Ruta | Acceso | Handler |
|---|---|---|---|
'''
for method, path, handler in routes:
    source, line, _ = functions[handler] if handler else (ROOT / 'xenios-movil/index.php', 1, '')
    output += f'| {method} | `{path}` | {access(method, path)} | [{handler or "health"}](../{source.relative_to(ROOT).as_posix()}#L{line}) |\n'
output += '''
## Salud

GET `/health` responde `ok`, `service` (`xenios-movil`) y `notasTrasladoVersion`. No conecta a MySQL; un 200 confirma el router y la clave REST, no la salud de todas las dependencias.

## Contratos por handler

Campos detectados directamente en el handler; no todos son obligatorios ni aplican a todas las subrutas de un handler compartido. Las respuestas son expresiones PHP del código, no respuestas observadas en producción. Las estructuras calculadas por funciones delegadas se consultan en la implementación enlazada y en INVENTARIO.md.
'''
for handler in dict.fromkeys(h for _, _, h in routes if h):
    source, line, body = functions[handler]
    output += f'\n### {handler}\n\nFuente: [{source.relative_to(ROOT).as_posix()}:{line}](../{source.relative_to(ROOT).as_posix()}#L{line}).\n\n'
    patterns = [('Query', r"\$_GET\['([^']+)'\]"),
                ('JSON/formulario', r"\$(?:body|b|input)\['([^']+)'\]"),
                ('Multipart', r"\$_FILES\['([^']+)'\]"),
                ('Controles locales', r'\b((?:require_[a-z_]+|[a-z_]+_require_[a-z_]+))\(')]
    for label, pattern in patterns:
        fields = sorted(set(re.findall(pattern, body)))
        if fields:
            output += label + ': ' + ', '.join('`' + f + '`' for f in fields) + '.\n\n'
    responses = re.findall(r'json_response\((.*?)\);', body, re.S)
    if responses:
        output += 'Respuestas declaradas:\n\n```php\n' + '\n'.join('json_response(' + v.strip() + ');' for v in responses) + '\n```\n'
output += '''
## Funciones fuera del router

| Método/ruta | Entrada | Acceso | Respuesta |
|---|---|---|---|
| GET `/` | Ninguna | Público | JSON `{"service":"Xenios API"}` |
| GET/POST `/xenios-movil/reset-password/` | GET token; POST token, clave, clave2 | Token temporal | HTML |
| POST `/actions/uploadAvatar/upload.php` | Multipart archivo JPG/PNG hasta 100 KB | X-Service-Key | Texto `Archivo recibido y guardado como: {nombre}` |
| POST `/actions/uploadImgPersonal/upload.php` | Multipart archivo JPG/PNG hasta 512 KB | X-Service-Key | Mismo contrato |
| POST `/actions/uploadAuthSalida/upload.php` | Multipart archivo PDF hasta 10 MB | X-Service-Key | Mismo contrato |
| POST `/actions/alicuotas/update.php` | Ver contrato específico en FUNCIONALIDADES.md | X-Service-Key y X-API-KEY | JSON |
| GET `/avatar/{nombre}` | Nombre generado | X-Service-Key | Imagen |
| GET `/personal/{nombre}` | Nombre generado | X-Service-Key | Imagen |
| GET `/authsalida/{nombre}` | Nombre generado | X-Service-Key | PDF |

Los dispositivos deben usar `/auth/avatar/imagen` y `/autorizaciones-salida/{id}/archivo`, que verifican propiedad; no reciben la clave de servicio.

## Ejemplos

```http
POST /xenios-movil/auth/login HTTP/1.1
Host: api.xenios.com.ar
X-API-KEY: <clave-cliente>
Content-Type: application/json

{"correo":"usuario@example.com","clave":"<contraseña>"}
```

Si corresponde MFA, continuar con POST `/auth/doble-factor` y los campos `desafio` y `codigo`. Usar la sesión obtenida de esta forma:

```http
GET /xenios-movil/auth/me HTTP/1.1
Host: api.xenios.com.ar
X-API-KEY: <clave-cliente>
Authorization: Bearer <sesion>
```
'''
(DOCS / 'API.md').write_text(output, encoding='utf-8')

inventory = '# Inventario técnico\n\nÍndice de todas las funciones PHP. Consultar FUNCIONALIDADES.md para reglas de negocio y API.md para rutas. Las firmas enlazan al cuerpo real, incluidos campos delegados y estructuras anidadas.\n'
variables = {}
for source in sorted(ROOT.rglob('*.php')):
    text = source.read_text(encoding='utf-8-sig')
    matches = list(re.finditer(r'^\s*function\s+(\w+)\s*\(.*$', text, re.M))
    if matches:
        inventory += '\n## ' + source.relative_to(ROOT).as_posix() + '\n\n| Función | Firma |\n|---|---|\n'
        for match in matches:
            line = text.count('\n', 0, match.start()) + 1
            signature = match[0].strip().replace('|', '\\|')
            inventory += f'| [{match[1]}](../{source.relative_to(ROOT).as_posix()}#L{line}) | `{signature}` |\n'
    for match in re.finditer(r"(?:getenv|xenios_movil_env_value|xenios_movil_env_int)\('([A-Z][A-Z0-9_]+)'", text):
        variables.setdefault(match[1], set()).add(source.relative_to(ROOT).as_posix())
(DOCS / 'INVENTARIO.md').write_text(inventory, encoding='utf-8')

config = '''# Configuración

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
'''
for key, sources in sorted(variables.items()):
    config += '| `' + key + '` | ' + ', '.join('`' + p + '`' for p in sorted(sources)) + ' |\n'
(DOCS / 'CONFIGURACION.md').write_text(config, encoding='utf-8')
print(f'Generated reference: {len(routes)} REST routes, {len(functions)} core functions.')
