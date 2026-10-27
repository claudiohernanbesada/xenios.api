"""Local integration checks using real Apache rewrites and PHP. No database writes."""
import argparse
import base64
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('--php', required=True)
parser.add_argument('--httpd', required=True)
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
php = Path(args.php).resolve()
apache = Path(args.httpd).resolve()
env = os.environ.copy()
env.update(XENIOS_API_TOKEN='a' * 64, XENIOS_SERVICE_TOKEN='b' * 64,
           XENIOS_JWT_SECRET='c' * 64, XENIOS_WEB_BRIDGE_SECRET='d' * 64)
checks = 0

def expect(condition, label):
    global checks
    if not condition:
        raise AssertionError(label)
    checks += 1

for source in root.rglob('*.php'):
    result = subprocess.run([str(php), '-n', '-l', str(source)], capture_output=True, text=True)
    expect(result.returncode == 0, result.stdout + result.stderr)

unit = '''require 'xenios-movil/lib/auth.php';
require '_inc/api_tokens.php';
$secret = str_repeat('s', 64);
$token = issue_token('user', 'test@example.com', $secret, 60);
if (!verify_token($token, $secret)) exit(1);
if (verify_token($token . 'x', $secret) !== null) exit(2);
if (verify_token($token, str_repeat('x', 64)) !== null) exit(3);
if (verify_token(issue_token('user','mail',$secret,0), $secret) !== null) exit(4);
$_GET['token'] = $token;
$_GET['api_key'] = str_repeat('a',64);
if (bearer_token() !== null || xenios_api_token_recibido() !== '') exit(5);
echo 'Token checks OK';'''
result = subprocess.run([str(php), '-n', '-r', unit], cwd=root, env=env, capture_output=True, text=True)
expect(result.returncode == 0, result.stdout + result.stderr)

with tempfile.TemporaryDirectory(prefix='xenios-env-check-') as env_temp:
    private_env = Path(env_temp) / '.env'
    private_env.write_text('XENIOS_DB_USER=private-test-user\n'
                           'XENIOS_JWT_SECRET="private-test-secret"\n'
                           'EXPO_PUBLIC_APP_VERSION=2.0.0\n'
                           'XENIOS_MOVIL_ENV_FILE=ignored-path\n', encoding='utf-8')
    loader_env = {key: value for key, value in os.environ.items()
                  if not key.startswith(('XENIOS_', 'EXPO_PUBLIC_APP_VERSION'))}
    loader_env['XENIOS_MOVIL_ENV_FILE'] = str(private_env)
    loader_check = '''$config = require 'xenios-movil/config.php';
if ($config['db']['user'] !== 'private-test-user') exit(1);
if ($config['jwt_secret'] !== 'private-test-secret') exit(2);
if ($config['app_version']['android_latest_version'] !== '2.0.0') exit(3);
if (xenios_env_path() !== getenv('XENIOS_MOVIL_ENV_FILE')) exit(4);'''
    result = subprocess.run([str(php), '-n', '-r', loader_check], cwd=root,
                            env=loader_env, capture_output=True, text=True)
    expect(result.returncode == 0, 'private dotenv loads consistently: ' + result.stderr)
    loader_env.update(XENIOS_DB_USER='process-test-user', EXPO_PUBLIC_APP_VERSION='3.0.0')
    precedence_check = '''$config = require 'xenios-movil/config.php';
if ($config['db']['user'] !== 'process-test-user') exit(1);
if ($config['app_version']['android_latest_version'] !== '3.0.0') exit(2);'''
    result = subprocess.run([str(php), '-n', '-r', precedence_check], cwd=root,
                            env=loader_env, capture_output=True, text=True)
    expect(result.returncode == 0, 'process environment takes precedence: ' + result.stderr)
    loader_env.pop('XENIOS_DB_USER')
    loader_env['XENIOS_MOVIL_ENV_FILE'] = str(Path(env_temp) / 'missing.env')
    missing_check = '''$config = require 'xenios-movil/config.php';
if ($config['db']['user'] !== '' || $config['jwt_secret'] !== '') exit(1);'''
    result = subprocess.run([str(php), '-n', '-r', missing_check], cwd=root,
                            env=loader_env, capture_output=True, text=True)
    expect(result.returncode == 0, 'missing private dotenv never loads public files: ' + result.stderr)

with tempfile.TemporaryDirectory(prefix='xenios-api-check-') as temp:
    temp = Path(temp)
    env['XENIOS_RATE_LIMIT_DIR'] = str(temp / 'rate')
    env['XENIOS_AVATAR_DIR'] = str(temp / 'avatars')
    env['XENIOS_PERSONAL_DIR'] = str(temp / 'personal')
    env['PATH_LEGAJO_PERSONAL'] = str(temp / 'legajo')
    (temp / 'legajo').mkdir()
    (temp / 'php.ini').write_text(
        f'display_errors=Off\nlog_errors=On\nextension_dir="{php.parent.as_posix()}/ext"\nextension=fileinfo\n',
        encoding='ascii')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    config = temp / 'httpd.conf'
    home = apache.parent.parent.as_posix()
    modules = ['authz_core', 'authz_host', 'rewrite', 'headers', 'dir', 'mime']
    content = f'ServerRoot "{home}"\nListen 127.0.0.1:{port}\nServerName localhost\n'
    for module in modules:
        content += f'LoadModule {module}_module "{home}/modules/mod_{module}.so"\n'
    content += f'''LoadModule php_module "{php.parent.as_posix()}/php8apache2_4.dll"
PHPIniDir "{temp.as_posix()}"
AddHandler application/x-httpd-php .php
TypesConfig "{home}/conf/mime.types"
DocumentRoot "{root.as_posix()}"
DirectoryIndex index.php
ErrorLog "{temp.as_posix()}/error.log"
PidFile "{temp.as_posix()}/httpd.pid"
<Directory "{root.as_posix()}">
AllowOverride All
Require all granted
</Directory>
'''
    config.write_text(content, encoding='utf-8')
    result = subprocess.run([str(apache), '-t', '-f', str(config)], capture_output=True, text=True, env=env)
    expect(result.returncode == 0, result.stdout + result.stderr)
    log = open(temp / 'console.log', 'w')
    process = subprocess.Popen([str(apache), '-X', '-f', str(config)], env=env, stdout=log, stderr=log)
    def request(path, headers=None, data=None):
        req = urllib.request.Request(f'http://127.0.0.1:{port}{path}', headers=headers or {}, data=data)
        try:
            response = urllib.request.urlopen(req, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, dict(response.headers), response.read()
    try:
        for _ in range(50):
            try:
                status, headers, body = request('/')
                break
            except (OSError, urllib.error.URLError):
                if process.poll() is not None:
                    raise RuntimeError((temp / 'console.log').read_text())
                time.sleep(0.1)
        expect(status == 200 and b'Xenios API' in body, 'root health')
        key = {'X-API-KEY': 'a' * 64}
        expect(request('/xenios-movil/health')[0] == 401, 'missing key')
        expect(request('/xenios-movil/health?api_key=' + 'a' * 64)[0] == 401, 'query key rejected')
        expect(request('/xenios-movil/health', key)[0] == 200, 'authenticated health')
        status, headers, _ = request('/xenios-movil/health', {**key, 'Origin': 'https://evil.example'})
        expect('Access-Control-Allow-Origin' not in headers, 'untrusted CORS')
        _, headers, _ = request('/xenios-movil/health', {**key, 'Origin': 'https://app.xenios.com.ar'})
        expect(headers.get('Access-Control-Allow-Origin') == 'https://app.xenios.com.ar', 'allowed CORS')
        expect(headers.get('X-Content-Type-Options') == 'nosniff', 'nosniff header')
        for path in ['/.env', '/.git/config', '/_inc/credenciales.php', '/xenios-movil/config.php',
                     '/xenios-movil/tools/ver_tokens_legajo.php', '/xenios-movil/lib/auth.php',
                     '/docs/API.md', '/tools/generate_docs.py', '/tests/security_check.py']:
            blocked_status = request(path)[0]
            expect(blocked_status == 403, f'blocked {path}: {blocked_status}')
        for path in ['/avatar/index.php', '/personal/index.php', '/authsalida/index.php',
                     '/actions/uploadAvatar/upload.php', '/actions/uploadImgPersonal/upload.php',
                     '/actions/uploadAuthSalida/upload.php', '/actions/alicuotas/update.php',
                     '/actions/uploadLegajo/upload.php', '/legajo/index.php']:
            expect(request(path)[0] == 401, 'protected ' + path)
        # Existing files must also pass through the controller.
        asset = root / 'personal' / 'security-check-existing.txt'
        try:
            asset.write_text('private-test-content')
            expect(request('/personal/' + asset.name)[0] == 401, 'static file bypass denied')
        finally:
            asset.unlink(missing_ok=True)
        expect(request('/xenios-movil/reportes/asistencia', key)[0] == 401, 'client key cannot read report')
        expect(request('/actions/uploadAvatar/upload.php', {'X-Service-Key': 'b' * 64}, b'')[0] == 400, 'empty upload rejected')
        expect(request('/', {'Host': 'api.xenios.com.ar'})[0] == 403, 'public host requires HTTPS')
        expect(request('/actions/uploadAvatar/upload.php', {'X-Service-Key': 'a' * 64}, b'')[0] == 401, 'client secret cannot upload')
        def upload(name, payload, endpoint='/actions/uploadAvatar/upload.php'):
            boundary = 'XeniosTestBoundary'
            multipart = (f'--{boundary}\r\nContent-Disposition: form-data; name="archivo"; filename="{name}"\r\n'
                         'Content-Type: image/png\r\n\r\n').encode() + payload + f'\r\n--{boundary}--\r\n'.encode()
            return request(endpoint, {
                'X-Service-Key': 'b' * 64,
                'Content-Type': 'multipart/form-data; boundary=' + boundary,
            }, multipart)
        expect(upload('fake.png', b'<?php echo "bad"; ?>')[0] == 400, 'fake PNG rejected')
        png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN3sAAAAASUVORK5CYII=')
        expect(upload('image.jpg', png)[0] == 400, 'content and extension mismatch rejected')
        expect(upload('image.png', png)[0] == 200, 'valid PNG accepted')
        legajo_upload = '/actions/uploadLegajo/upload.php'
        service = {'X-Service-Key': 'b' * 64}
        expect(request(legajo_upload, service)[0] == 405, 'legajo upload POST only')
        expect(request(legajo_upload, service, b'')[0] == 400, 'legajo missing file')
        expect(upload('bad.pdf', b'<?php echo 1; ?>', legajo_upload)[0] == 400, 'legajo fake PDF')
        expect(upload('bad.jpg', png, legajo_upload)[0] == 400, 'legajo MIME mismatch')
        expect(upload('bad.php', png, legajo_upload)[0] == 400, 'legajo executable extension')
        name = '19b1432a-35db-4525-aab5-ab392dcc394c.PNG'
        status, _, body = upload(name, png, legajo_upload)
        document = json.loads(body)
        expect(status == 201 and document['archivo'] == name, 'legajo preserves web GUID filename')
        expect(document['tamano'] == len(png), 'legajo reports stored size')
        expect((temp / 'legajo' / name).read_bytes() == png, 'legajo stored outside public directory')
        expect(upload(name, png, legajo_upload)[0] == 409, 'legajo refuses overwrite')
        expect(request('/legajo/' + name)[0] == 401, 'legajo download requires service key')
        expect(request('/legajo/' + name, key)[0] == 401, 'legajo client key is insufficient')
        status, headers, body = request('/legajo/' + name, service)
        expect(status == 200 and body == png, 'legajo exact download round trip')
        expect(headers['Content-Type'] == 'image/png', 'legajo content type')
        expect(headers['Content-Disposition'].startswith('attachment;'), 'legajo attachment disposition')
        expect(headers['Cache-Control'] == 'private, no-store', 'legajo private response')
        for invalid in ['../.env', '%2e%2e%2f.env', name + '/extra', 'file.php']:
            expect(request('/legajo/index.php?f=' + invalid, service)[0] == 400, 'legajo rejects unsafe name')
        expect(request('/legajo/' + '0' * 32 + '.pdf', service)[0] == 404, 'legajo missing document')
        status, _, body = upload('scan.png', png, legajo_upload)
        expect(status == 201 and json.loads(body)['archivo'] != 'scan.png', 'legajo generates safe name')
        pdf = b'%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n'
        status, _, body = upload('certificate.pdf', pdf, legajo_upload)
        expect(status == 201, 'legajo accepts PDF')
        pdf_name = json.loads(body)['archivo']
        expect(request('/legajo/' + pdf_name, service)[2] == pdf, 'legajo PDF round trip')
        # A large valid PDF proves the route overrides the general 11 MB request limit.
        large_pdf = pdf + b' ' * (12 * 1024 * 1024)
        expect(upload('large.pdf', large_pdf, legajo_upload)[0] == 201, 'legajo supports documents above 11 MB')
        expect(not list((temp / 'legajo').glob('.upload-*')), 'legajo leaves no staging files')
        for _ in range(20):
            request('/xenios-movil/auth/login', {}, b'{}')
        expect(request('/xenios-movil/auth/login', {}, b'{}')[0] == 429, 'auth rate limit')
    finally:
        process.terminate()
        process.wait(timeout=10)
        log.close()
print(f'OK: {checks} syntax, token and Apache/PHP integration checks.')
