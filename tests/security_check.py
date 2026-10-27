"""Local integration checks using real Apache rewrites and PHP. No database writes."""
import argparse
import base64
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

with tempfile.TemporaryDirectory(prefix='xenios-api-check-') as temp:
    temp = Path(temp)
    env['XENIOS_RATE_LIMIT_DIR'] = str(temp / 'rate')
    env['XENIOS_AVATAR_DIR'] = str(temp / 'avatars')
    env['XENIOS_PERSONAL_DIR'] = str(temp / 'personal')
    (temp / 'php.ini').write_text('display_errors=Off\nlog_errors=On\n', encoding='ascii')
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
                     '/actions/uploadAuthSalida/upload.php', '/actions/alicuotas/update.php']:
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
        def upload(name, payload):
            boundary = 'XeniosTestBoundary'
            multipart = (f'--{boundary}\r\nContent-Disposition: form-data; name="archivo"; filename="{name}"\r\n'
                         'Content-Type: image/png\r\n\r\n').encode() + payload + f'\r\n--{boundary}--\r\n'.encode()
            return request('/actions/uploadAvatar/upload.php', {
                'X-Service-Key': 'b' * 64,
                'Content-Type': 'multipart/form-data; boundary=' + boundary,
            }, multipart)
        expect(upload('fake.png', b'<?php echo "bad"; ?>')[0] == 400, 'fake PNG rejected')
        png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aN3sAAAAASUVORK5CYII=')
        expect(upload('image.jpg', png)[0] == 400, 'content and extension mismatch rejected')
        expect(upload('image.png', png)[0] == 200, 'valid PNG accepted')
        for _ in range(20):
            request('/xenios-movil/auth/login', {}, b'{}')
        expect(request('/xenios-movil/auth/login', {}, b'{}')[0] == 429, 'auth rate limit')
    finally:
        process.terminate()
        process.wait(timeout=10)
        log.close()
print(f'OK: {checks} syntax, token and Apache/PHP integration checks.')
