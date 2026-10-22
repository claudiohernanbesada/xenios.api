<?php
declare(strict_types=1);

// Front controller: todas las rutas de la API móvil pasan por acá
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/avatar.php';
require_once __DIR__ . '/lib/auth_salida_file.php';
require_once __DIR__ . '/lib/asistencia_calendario.php';
require_once __DIR__ . '/lib/horas_trabajadas_empleado.php';
require_once __DIR__ . '/lib/novedades.php';
require_once __DIR__ . '/lib/fichadas_push.php';
require_once __DIR__ . '/lib/password_reset.php';
require_once __DIR__ . '/lib/vacaciones.php';
require_once __DIR__ . '/lib/auth_salida_list.php';
require_once __DIR__ . '/lib/auth_salida_scan.php';
require_once __DIR__ . '/lib/notas_traslado.php';
require_once __DIR__ . '/lib/usuarios.php';
require_once __DIR__ . '/lib/areas.php';
require_once __DIR__ . '/lib/inout_seguridad.php';
require_once __DIR__ . '/lib/reportes_sgi.php';
require_once __DIR__ . '/lib/horas_extra_motivo_pendientes.php';

$config = require __DIR__ . '/config.php';
apply_cors($config);

// Path limpio y método HTTP del request entrante
$path = current_api_path($config);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Preflight CORS — el browser manda OPTIONS antes del POST/DELETE real
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// API key requerida para todas las rutas.
require_api_key($config);

if ($method === 'GET' && $path === '/health') {
    json_response([
        'ok' => true,
        'service' => 'sgi-movil',
        'notasTrasladoVersion' => defined('NOTAS_TRASLADO_API_VERSION') ? NOTAS_TRASLADO_API_VERSION : null,
    ], 200, $config);
    exit;
}

// Si MySQL no responde, cortamos acá con 500
try {
    $pdo = db_connect($config);
} catch (Throwable $e) {
    json_response(['message' => 'Error de conexión a base de datos'], 500, $config);
    exit;
}

// Enrutador: método + path → handler correspondiente
try {
    match (true) {
        $path === '/inout-seguridad' || str_starts_with($path, '/inout-seguridad/') => handle_inout_seguridad($pdo, $config, $path, $method),
        $method === 'GET' && $path === '/app-version' => handle_app_version($pdo, $config),
        $method === 'POST' && $path === '/auth/login' => handle_login($pdo, $config),
        $method === 'POST' && $path === '/auth/doble-factor' => handle_doble_factor($pdo, $config),
        $method === 'POST' && $path === '/auth/logout' => handle_logout($pdo, $config),
        $method === 'POST' && $path === '/auth/recuperar-clave' => handle_recuperar_clave_solicitud($pdo, $config),
        $method === 'GET' && $path === '/auth/recuperar-clave/validar' => handle_recuperar_clave_validar($pdo, $config),
        $method === 'POST' && $path === '/auth/recuperar-clave/completar' => handle_recuperar_clave_completar($pdo, $config),
        $method === 'GET' && $path === '/auth/me' => handle_me($pdo, $config),
        $method === 'POST' && $path === '/auth/cambiar-clave' => handle_cambiar_clave($pdo, $config),
        $method === 'POST' && $path === '/auth/avatar' => handle_avatar_upload($pdo, $config),
        $method === 'DELETE' && $path === '/auth/avatar' => handle_avatar_delete($pdo, $config),
        $method === 'GET' && $path === '/auth/avatar/imagen' => handle_avatar_imagen($pdo, $config),
        $method === 'GET' && $path === '/fichadas' => handle_fichadas($pdo, $config),
        $method === 'GET' && $path === '/autorizaciones-salida' => handle_autorizaciones($pdo, $config),
        $method === 'POST' && $path === '/auth-salida/scan' => handle_auth_salida_scan($pdo, $config),
        $method === 'GET' && $path === '/asistencia' => handle_asistencia($pdo, $config),
        $method === 'GET' && $path === '/vacaciones' => handle_vacaciones($pdo, $config),
        $method === 'POST' && $path === '/push/registrar-token' => handle_push_registrar_token($pdo, $config),
        $method === 'POST' && $path === '/push/fichadas/evento' => handle_push_fichadas_evento($pdo, $config),
        $method === 'POST' && $path === '/push/fichadas/procesar' => handle_push_fichadas_procesar($pdo, $config),
        $method === 'GET' && $path === '/novedades' => handle_novedades_list($pdo, $config),
        str_starts_with($path, '/novedades/') => handle_novedades_routes($pdo, $config, $path, $method),
        $method === 'GET' && $path === '/usuarios' => handle_usuarios_list($pdo, $config),
        $method === 'GET' && $path === '/areas' => handle_areas_list($pdo, $config),
        $method === 'GET' && $path === '/reportes/asistencia' => handle_reporte_asistencia_sgi($pdo, $config),
        $method === 'GET' && $path === '/reportes/asistencia-detallada' => handle_reporte_asistencia_detallada_sgi($pdo, $config),
        $method === 'GET' && $path === '/reportes/horas-trabajadas' => handle_reporte_horas_trabajadas_sgi($pdo, $config),
        $method === 'GET' && $path === '/reportes/horas-extras-diarias' => handle_reporte_horas_extras_diarias_sgi($pdo, $config),
        $method === 'POST' && $path === '/horas-extras/motivos-pendientes/enviar-mail' => handle_horas_extra_motivo_pendientes_mail($pdo, $config),
        $method === 'GET' && $path === '/notas-traslado' => handle_notas_traslado_list($pdo, $config),
        $method === 'POST' && $path === '/notas-traslado/scan' => handle_notas_traslado_scan($pdo, $config),
        $method === 'POST' && $path === '/novedades' => handle_novedades_enviar($pdo, $config),
        default => handle_notas_traslado_routes($pdo, $config, $path, $method)
            ? null
            : handle_novedades_routes($pdo, $config, $path, $method),
    };
// Cualquier excepción sin atrapar en un handler → 500 genérico
} catch (Throwable $e) {
    error_log('[sgi-movil] Error no controlado en ' . $method . ' ' . $path . ': ' . $e->getMessage());
    json_response(['message' => 'Error interno del servidor'], 500, $config);
}

// POST /auth/recuperar-clave — envía mail con enlace (respuesta genérica)
function handle_app_version(PDO $pdo, array $config): void
{
    require_auth($pdo, $config);

    $versionConfig = $config['app_version'] ?? [];
    $androidLatestVersion = (string) ($versionConfig['android_latest_version'] ?? '');
    $androidStoreUrl = (string) ($versionConfig['android_store_url'] ?? '');
    $androidPlayVersion = fetch_google_play_version($androidStoreUrl);

    if ($androidPlayVersion !== '' && compare_app_versions($androidLatestVersion, $androidPlayVersion) < 0) {
        $androidLatestVersion = $androidPlayVersion;
    }

    json_response([
        'android' => [
            'latestVersion' => $androidLatestVersion,
            'storeUrl' => $androidStoreUrl,
        ],
        'ios' => [
            'latestVersion' => (string) ($versionConfig['ios_latest_version'] ?? ''),
            'storeUrl' => (string) ($versionConfig['ios_store_url'] ?? ''),
        ],
    ], 200, $config);
}

function compare_app_versions(string $left, string $right): int
{
    $leftParts = normalize_app_version($left);
    $rightParts = normalize_app_version($right);
    $max = max(count($leftParts), count($rightParts));

    for ($i = 0; $i < $max; $i += 1) {
        $l = $leftParts[$i] ?? 0;
        $r = $rightParts[$i] ?? 0;
        if ($l > $r) {
            return 1;
        }
        if ($l < $r) {
            return -1;
        }
    }

    return 0;
}

function handle_horas_extra_motivo_pendientes_mail(PDO $pdo, array $config): void
{
    try {
        $resultado = horas_extra_motivo_pendientes_enviar($pdo, $config);
        error_log('[horas_extra_motivo] Resultado envio pendientes: ' . json_encode([
            'ok' => $resultado['ok'],
            'desde' => $resultado['desde'] ?? null,
            'hasta' => $resultado['hasta'] ?? null,
            'usuariosEvaluados' => $resultado['usuariosEvaluados'] ?? null,
            'enviados' => $resultado['enviados'] ?? null,
            'omitidos' => $resultado['omitidos'] ?? null,
            'errores' => $resultado['errores'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        json_response($resultado, 200, $config);
    } catch (Throwable $e) {
        error_log('[horas_extra_motivo] Error enviando pendientes: ' . $e->getMessage());
        json_response([
            'ok' => false,
            'message' => 'Error interno al enviar notificaciones de horas extras pendientes.',
        ], 500, $config);
    }
}

function normalize_app_version(string $version): array
{
    $parts = preg_split('/[.\-]/', $version) ?: [];

    return array_map(
        static fn(string $part): int => (int) preg_replace('/\D+/', '', $part),
        $parts
    );
}

function fetch_google_play_version(string $storeUrl): string
{
    if ($storeUrl === '' || !preg_match('/[?&]id=([^&]+)/', $storeUrl, $matches)) {
        return '';
    }

    $packageName = rawurldecode($matches[1]);
    if ($packageName === '') {
        return '';
    }

    $url = 'https://play.google.com/store/apps/details?id=' . rawurlencode($packageName) . '&hl=en&gl=US';
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 4,
            'header' => implode("\r\n", [
                'Accept: text/html',
                'User-Agent: Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36',
            ]),
        ],
    ]);

    $html = @file_get_contents($url, false, $context);
    if (!is_string($html) || $html === '') {
        return '';
    }

    return preg_match('/"141":\s*\[\s*\[\s*\[\s*"([^"]+)"/', $html, $versionMatch)
        ? trim($versionMatch[1])
        : '';
}

function handle_recuperar_clave_solicitud(PDO $pdo, array $config): void
{
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $correo = strtolower(trim((string) ($body['correo'] ?? '')));
    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        json_response(['message' => 'Ingresá un correo electrónico válido'], 400, $config);
        return;
    }

    $result = password_reset_request($pdo, $config, $correo);
    $code = ($result['ok'] ?? false) ? 200 : 500;
    json_response($result, $code, $config);
}

// GET /auth/recuperar-clave/validar?token= — comprueba token antes del formulario web
function handle_recuperar_clave_validar(PDO $pdo, array $config): void
{
    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        json_response(['ok' => false, 'message' => 'Token requerido'], 400, $config);
        return;
    }

    $result = password_reset_validate($pdo, $token);
    json_response($result, ($result['ok'] ?? false) ? 200 : 400, $config);
}

// POST /auth/recuperar-clave/completar — nueva clave con token del mail (sin sesión)
function handle_recuperar_clave_completar(PDO $pdo, array $config): void
{
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $token = trim((string) ($body['token'] ?? ''));
    $nueva = (string) ($body['nuevaClave'] ?? '');
    $confirmacion = (string) ($body['confirmacion'] ?? '');

    if ($token === '') {
        json_response(['message' => 'Token requerido'], 400, $config);
        return;
    }

    $result = password_reset_complete($pdo, $config, $token, $nueva, $confirmacion);
    json_response($result, ($result['ok'] ?? false) ? 200 : 400, $config);
}

// POST /auth/login — mail + clave, devuelve JWT y datos del usuario
function handle_login(PDO $pdo, array $config): void
{
    header('Cache-Control: no-store');
    if (strlen((string) ($config['jwt_secret'] ?? '')) < 32) {
        json_response(['message' => 'El inicio de sesión no está configurado. Contactá al administrador.'], 503, $config);
        return;
    }
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $correo = strtolower(trim((string) ($body['correo'] ?? '')));
    $clave = (string) ($body['clave'] ?? '');

    if ($correo === '' || $clave === '') {
        json_response(['message' => 'Correo y contraseña requeridos'], 400, $config);
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT Id, Nombre, Apellido, Mail, Estado, ImgPerfil, RequiereDobleFactor, DobleFactorVersion
         FROM Usuarios
         WHERE LOWER(Mail) = :mail AND Passwd = :passwd AND Estado > 0 AND Estado < 3
         LIMIT 1'
    );
    $stmt->execute(['mail' => $correo, 'passwd' => sha256_password($clave)]);
    $user = $stmt->fetch();

    if (!$user) {
        json_response(['message' => 'Usuario o contraseña incorrectos'], 401, $config);
        return;
    }
    if ((int) $user['Estado'] !== 1) {
        json_response(['message' => 'Usuario deshabilitado'], 403, $config);
        return;
    }

    if ((bool) $user['RequiereDobleFactor']) {
        try {
            $result = doble_factor_solicitar($config, 'iniciar', ['usuarioId' => $user['Id'], 'credencial' => sha256_password($clave)]);
            if ($result['status'] !== 200) {
                json_response(doble_factor_error($result, 'No se pudo iniciar la verificación. Volvé a ingresar.'), $result['status'], $config);
                return;
            }
            json_response($result['data'], 200, $config);
        } catch (Throwable $e) {
            error_log('[sgi-movil] No se pudo iniciar doble factor.');
            json_response(['message' => 'La verificación de doble factor no está disponible. Intentá más tarde.'], 503, $config);
        }
        return;
    }
    completar_login_movil($pdo, $config, $user);
}

function handle_doble_factor(PDO $pdo, array $config): void
{
    header('Cache-Control: no-store');
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $desafio = (string) ($body['desafio'] ?? '');
    $codigo = (string) ($body['codigo'] ?? '');
    if ($desafio === '' || strlen($desafio) > 2048 || !preg_match('/^[0-9]{6}$/D', $codigo)) {
        json_response(['message' => 'Ingresá el código de seis dígitos del autenticador.'], 400, $config);
        return;
    }
    try {
        $result = doble_factor_solicitar($config, 'verificar', ['desafio' => $desafio, 'codigo' => $codigo]);
        if ($result['status'] !== 200) {
            json_response(doble_factor_error($result, 'Verificación inválida. Volvé a iniciar sesión.'), $result['status'], $config);
            return;
        }
        if (!doble_factor_prueba_valida($config, $result['data'])) throw new RuntimeException('Prueba de segundo factor inválida.');
        $stmt = $pdo->prepare('SELECT Id, Nombre, Apellido, Mail, Estado, ImgPerfil, RequiereDobleFactor,
            DobleFactorVersion, (DobleFactorSecreto IS NOT NULL) AS DobleFactorConfigurado FROM Usuarios WHERE Id = :id LIMIT 1');
        $stmt->execute(['id' => $result['data']['usuarioId']]);
        $user = $stmt->fetch();
        $version = (string) $result['data']['version'];
        if (!$user || (int) $user['Estado'] !== 1 || !doble_factor_token_valido($user, ['mfa' => true, 'mfaVersion' => $version])) {
            json_response(['message' => 'La configuración del usuario cambió. Volvé a iniciar sesión.'], 401, $config);
            return;
        }
        completar_login_movil($pdo, $config, $user, $version);
    } catch (Throwable $e) {
        error_log('[sgi-movil] No se pudo completar doble factor.');
        json_response(['message' => 'La verificación de doble factor no está disponible. Intentá más tarde.'], 503, $config);
    }
}

function completar_login_movil(PDO $pdo, array $config, array $user, ?string $mfaVersion = null): void
{
    $correo = (string) $user['Mail'];
    $stmtLogin = $pdo->prepare(
        'UPDATE Usuarios SET LastLogin = NOW(), Plataforma = :plataforma WHERE Id = :id'
    );
    $stmtLogin->execute([
        'id' => (string) $user['Id'],
        'plataforma' => 'mobil',
    ]);
    registrar_log_sgi($pdo, (string) $user['Id'], 'Login', 'Inicio de sesión exitoso - ' . $correo, 'mobil');

    $roles = fetch_user_roles($pdo, (string) $user['Id']);
    $personal = fetch_personal_by_mail($pdo, $correo);

    $token = issue_token(
        (string) $user['Id'],
        $correo,
        $config['jwt_secret'],
        (int) $config['jwt_ttl_seconds'],
        $mfaVersion
    );

    json_response([
        'token' => $token,
        'usuario' => build_usuario_response($user, $roles, $personal, $config),
    ], 200, $config);
}

// POST /auth/logout — registra cierre de sesión móvil
function handle_logout(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    registrar_log_sgi($pdo, (string) $auth['user']['Id'], 'Logout', 'Cierre de sesión exitoso', 'mobil');

    json_response(['ok' => true], 200, $config);
}

// GET /auth/me — perfil del usuario logueado según el token
function handle_me(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    $personal = fetch_personal_by_mail($pdo, (string) $auth['user']['Mail']);
    json_response([
        'usuario' => build_usuario_response(
            $auth['user'],
            $auth['roles'],
            $personal,
            $config
        ),
    ], 200, $config);
}

// POST /auth/cambiar-clave — nueva clave con validación de política
function handle_cambiar_clave(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $actual = (string) ($body['claveActual'] ?? '');
    $nueva = (string) ($body['nuevaClave'] ?? '');
    $confirmacion = (string) ($body['confirmacion'] ?? '');

    if ($actual === '') {
        json_response(['message' => 'La contraseña actual es requerida'], 400, $config);
        return;
    }

    $stmtActual = $pdo->prepare(
        'SELECT Id FROM Usuarios WHERE Id = :id AND Passwd = :passwd LIMIT 1'
    );
    $stmtActual->execute([
        'id' => $auth['user']['Id'],
        'passwd' => sha256_password($actual),
    ]);
    if (!$stmtActual->fetch()) {
        json_response(['message' => 'La contraseña actual no es correcta'], 400, $config);
        return;
    }

    if ($nueva !== $confirmacion) {
        json_response(['message' => 'Las contraseñas no coinciden'], 400, $config);
        return;
    }

    $policyError = validate_password_policy($nueva);
    if ($policyError !== null) {
        json_response(['message' => $policyError], 400, $config);
        return;
    }

    $stmt = $pdo->prepare(
        'UPDATE Usuarios SET Passwd = :passwd, dateUpdate = NOW(), LastChPasswd = NOW() WHERE Id = :id'
    );
    $stmt->execute([
        'passwd' => sha256_password($nueva),
        'id' => $auth['user']['Id'],
    ]);

    json_response(['ok' => true], 200, $config);
}

function registrar_log_sgi(PDO $pdo, string $usuarioId, string $accion, string $detalle, string $plataforma): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO Logs (UsuarioId, IP, Accion, Detalle, Plataforma, DateAction)
         VALUES (:usuarioId, :ip, :accion, :detalle, :plataforma, NOW())'
    );
    $stmt->execute([
        'usuarioId' => $usuarioId,
        'ip' => cliente_ip_sgi(),
        'accion' => $accion,
        'detalle' => $detalle,
        'plataforma' => $plataforma,
    ]);
}

function cliente_ip_sgi(): string
{
    $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $parts = array_map('trim', explode(',', $forwarded));
        if (($parts[0] ?? '') !== '') {
            return $parts[0];
        }
    }

    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

// POST /auth/avatar — intermediario: guarda en disco según avatar_usuario en rutas_archivos.php
function handle_avatar_upload(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);

    if (!isset($_FILES['archivo'])) {
        json_response(['message' => 'No se recibió una imagen válida'], 400, $config);
        return;
    }

    $personal = fetch_personal_by_mail($pdo, (string) $auth['user']['Mail']);

    try {
        $fileName = save_uploaded_foto($_FILES['archivo'], SGI_AVATAR_REPO);
    } catch (Throwable $e) {
        json_response(['message' => $e->getMessage()], 500, $config);
        return;
    }

    if ($fileName === null) {
        $size = (int) ($_FILES['archivo']['size'] ?? 0);
        $err = (int) ($_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            json_response(['message' => 'No se recibió el archivo correctamente (error ' . $err . ').'], 400, $config);
            return;
        }
        if ($size > SGI_AVATAR_MAX_BYTES) {
            json_response(['message' => 'La imagen es demasiado grande (máximo 100 KB).'], 400, $config);
            return;
        }
        json_response([
            'message' => 'Imagen inválida. Use JPG o PNG.',
        ], 400, $config);
        return;
    }

    delete_archivo_fisico(SGI_AVATAR_REPO, $auth['user']['ImgPerfil'] ?? null);
    $stmt = $pdo->prepare(
        'UPDATE Usuarios SET ImgPerfil = :img, dateUpdate = NOW() WHERE Id = :id'
    );
    $stmt->execute(['img' => $fileName, 'id' => $auth['user']['Id']]);
    $auth['user']['ImgPerfil'] = $fileName;

    json_response([
        'ok' => true,
        'usuario' => build_usuario_response(
            $auth['user'],
            $auth['roles'],
            $personal,
            $config
        ),
    ], 200, $config);
}

// GET /auth/avatar/imagen — avatar opcional del usuario (no foto de legajo Personal)
function handle_avatar_imagen(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);

    apply_cors($config);

    if (stream_avatar_for_user($auth['user'])) {
        exit;
    }

    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'Sin avatar de perfil'], JSON_UNESCAPED_UNICODE);
}

// DELETE /auth/avatar — quita solo Usuarios.ImgPerfil (no toca Personal.Foto)
function handle_avatar_delete(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    $personal = fetch_personal_by_mail($pdo, (string) $auth['user']['Mail']);

    delete_archivo_fisico(SGI_AVATAR_REPO, $auth['user']['ImgPerfil'] ?? null);
    $stmt = $pdo->prepare(
        'UPDATE Usuarios SET ImgPerfil = NULL, dateUpdate = NOW() WHERE Id = :id'
    );
    $stmt->execute(['id' => $auth['user']['Id']]);
    $auth['user']['ImgPerfil'] = null;

    json_response([
        'ok' => true,
        'usuario' => build_usuario_response(
            $auth['user'],
            $auth['roles'],
            $personal,
            $config
        ),
    ], 200, $config);
}

// GET /fichadas?fecha=YYYY-MM-DD — marcaciones del día para el legajo del empleado
function handle_fichadas(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    require_empleado_role($auth['roles'], $config);
    $fecha = (string) ($_GET['fecha'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        json_response(['message' => 'Parámetro fecha inválido (YYYY-MM-DD)'], 400, $config);
        return;
    }

    $personal = fetch_personal_by_mail($pdo, (string) $auth['user']['Mail']);
    if ($personal === null) {
        json_response(['fichadas' => []], 200, $config);
        return;
    }

    $legajo = (int) $personal['Legajo'];
    // En FichadasGlobal el legajo puede estar con o sin ceros adelante
    $claves = normalizar_legajo_claves($legajo);
    $placeholders = implode(',', array_fill(0, count($claves), '?'));

    $sql = "SELECT Id, Fecha, Sede, Controlador, EsCargaManual
            FROM FichadasGlobal
            WHERE Estado = 1
              AND DATE(Fecha) = ?
              AND Legajo IN ($placeholders)
            ORDER BY Fecha ASC";

    $params = array_merge([$fecha], $claves);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $fichadas = array_map(static function (array $row): array {
        $dt = new DateTimeImmutable((string) $row['Fecha']);
        return [
            'id' => (int) $row['Id'],
            'fecha' => $dt->format('Y-m-d'),
            'hora' => $dt->format('H:i'),
            'sede' => (string) $row['Sede'],
            'controlador' => (string) $row['Controlador'],
            'esCargaManual' => (bool) $row['EsCargaManual'],
        ];
    }, $rows);

    json_response(['fichadas' => $fichadas], 200, $config);
}

// GET /autorizaciones-salida — listado de permisos de salida del personal logueado
function handle_autorizaciones(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    require_empleado_role($auth['roles'], $config);
    $personal = fetch_personal_empleado_completo($pdo, (string) $auth['user']['Mail']);

    if ($personal === null) {
        json_response(['autorizaciones' => []], 200, $config);
        return;
    }

    $autorizaciones = auth_salida_list_for_personal($pdo, $personal);

    json_response(['autorizaciones' => $autorizaciones], 200, $config);
}

// GET /autorizaciones-salida/{uuid}/archivo — descarga el PDF adjunto de una auth
function handle_autorizacion_archivo(PDO $pdo, array $config, string $path, string $method): void
{
    if ($method !== 'GET' || !preg_match('#^/autorizaciones-salida/([0-9a-f-]{36})/archivo$#i', $path, $m)) {
        json_response(['message' => 'Ruta no encontrada'], 404, $config);
        return;
    }

    $auth = require_auth($pdo, $config);
    require_empleado_role($auth['roles'], $config);
    $authId = $m[1];
    $personal = fetch_personal_by_mail($pdo, (string) $auth['user']['Mail']);

    if ($personal === null) {
        json_response(['message' => 'Sin autorizaciones asociadas'], 404, $config);
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT AuthFile FROM AuthSalida
         WHERE Id = :id AND PersonalId = :pid AND Estado = 1 LIMIT 1'
    );
    $stmt->execute(['id' => $authId, 'pid' => $personal['Id']]);
    $row = $stmt->fetch();

    if (!$row || empty($row['AuthFile'])) {
        json_response(['message' => 'Archivo no encontrado'], 404, $config);
        return;
    }

    $fileName = basename((string) $row['AuthFile']);

    apply_cors($config);

    if (stream_auth_salida_file($fileName, $config)) {
        exit;
    }

    json_response(['message' => 'El archivo no está en el servidor'], 404, $config);
}

// GET /asistencia?desde=&hasta= — calendario y resumen del empleado logueado
function handle_asistencia(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    require_empleado_role($auth['roles'], $config);

    $desde = (string) ($_GET['desde'] ?? '');
    $hasta = (string) ($_GET['hasta'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        json_response(['message' => 'Parámetros desde/hasta inválidos (YYYY-MM-DD)'], 400, $config);
        return;
    }
    $personalId = strtolower(trim((string) ($_GET['personalId'] ?? '')));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $personalId)) {
        json_response(['message' => 'No es posible obtener la información requerida en estos momentos. Si el problema persiste, contacte a la Gerencia de Sistemas e informe el error: NSIPID'], 400, $config);
        return;
    }

    $personal = fetch_personal_empleado_completo($pdo, (string) $auth['user']['Mail']);
    if ($personal === null) {
        json_response([
            'desde' => $desde,
            'hasta' => $hasta,
            'dias' => [],
            'resumen' => horas_trabajadas_build_resumen($pdo, ['Id' => '', 'Legajo' => 0], $desde, $hasta, $config),
        ], 200, $config);
        return;
    }
    if (strtolower((string) $personal['Id']) !== $personalId) {
        json_response(['message' => 'No tiene permisos para consultar ese empleado'], 403, $config);
        return;
    }

    json_response([
        'desde' => $desde,
        'hasta' => $hasta,
        'dias' => asistencia_build_calendario($pdo, $personal, $desde, $hasta),
        'resumen' => horas_trabajadas_build_resumen($pdo, $personal, $desde, $hasta, $config),
    ], 200, $config);
}

// GET /vacaciones?anio=YYYY — días correspondientes, consumidos y restantes del empleado
function handle_vacaciones(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    require_empleado_role($auth['roles'], $config);

    $anioRaw = (string) ($_GET['anio'] ?? date('Y'));
    if (!preg_match('/^\d{4}$/', $anioRaw)) {
        json_response(['message' => 'Parámetro anio inválido (YYYY)'], 400, $config);
        return;
    }
    $anio = (int) $anioRaw;

    $personal = fetch_personal_vacaciones($pdo, (string) $auth['user']['Mail']);
    if ($personal === null) {
        json_response([
            'anio' => $anio,
            'aniosDisponibles' => [(int) date('Y')],
            'fechaAlta' => null,
            'antiguedadAnios' => 0,
            'diasCorrespondientes' => 0,
            'diasConsumidos' => 0,
            'diasRestantes' => 0,
            'consumidas' => [],
        ], 200, $config);
        return;
    }

    try {
        $payload = vacaciones_build_response($pdo, $personal, $anio);
    } catch (InvalidArgumentException $e) {
        json_response(['message' => $e->getMessage()], 400, $config);
        return;
    }

    json_response($payload, 200, $config);
}

function novedades_require_tables(PDO $pdo, array $config): void
{
    $missing = novedades_missing_tables($pdo);
    if ($missing === []) {
        return;
    }
    json_response([
        'message' => 'Faltan tablas de novedades en la base MySQL de la API (credenciales.php del servidor): '
            . implode(', ', $missing)
            . '. Ejecutá movil_novedades.sql en esa misma base (p. ej. sgiraei).',
        'tablasFaltantes' => $missing,
    ], 503, $config);
    exit;
}

// POST /push/registrar-token — guarda el token nativo FCM/APNs del dispositivo
function handle_push_registrar_token(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    novedades_require_tables($pdo, $config);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $token = trim((string) ($body['pushToken'] ?? ''));
    $plataforma = novedades_normalizar_plataforma(
        isset($body['plataforma']) ? (string) $body['plataforma'] : null
    );
    if ($token === '') {
        json_response(['message' => 'pushToken requerido'], 400, $config);
        return;
    }
    if ($plataforma === null || !push_native_token_valido($token, $plataforma)) {
        json_response(['message' => 'Token nativo FCM/APNs inválido'], 400, $config);
        return;
    }

    novedades_registrar_token(
        $pdo,
        (string) $auth['user']['Id'],
        $token,
        $plataforma
    );

    json_response(['ok' => true], 200, $config);
}

// POST /push/fichadas/procesar — envía push remoto por fichadas nuevas aunque la app esté cerrada
function handle_push_fichadas_procesar(PDO $pdo, array $config): void
{
    if (!validar_api_key($config)) {
        json_response(['message' => 'Error de seguridad AK-NOT_FOUND'], 401, $config);
        return;
    }

    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $lookbackHours = isset($body['lookbackHours']) ? (int) $body['lookbackHours'] : 24;
    $limit = isset($body['limit']) ? (int) $body['limit'] : 200;
    $baselineOnly = (bool) ($body['baselineOnly'] ?? false);

    try {
        json_response(fichadas_push_procesar($pdo, $lookbackHours, $limit, $baselineOnly), 200, $config);
    } catch (Throwable $e) {
        error_log('[fichadas_push] No se pudo procesar push de fichadas: ' . $e->getMessage());
        json_response([
            'message' => 'No se pudo procesar push de fichadas',
            'detail' => $e->getMessage(),
        ], 500, $config);
    }
}

// GET /usuarios — listado de usuarios activos con sus áreas
// POST /push/fichadas/evento - registra recepcion/apertura de una notificacion de fichada
function handle_push_fichadas_evento(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    require_any_role($auth['roles'], $config);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $fichadaId = isset($body['fichadaId']) ? (int) $body['fichadaId'] : 0;
    $evento = trim((string) ($body['evento'] ?? ''));
    if ($fichadaId <= 0 || !in_array($evento, ['recibida', 'visualizada'], true)) {
        json_response(['message' => 'Evento de fichada invalido'], 400, $config);
        return;
    }

    $ok = fichadas_push_registrar_evento_usuario(
        $pdo,
        (string) $auth['user']['Id'],
        $fichadaId,
        $evento
    );

    json_response(['ok' => $ok], 200, $config);
}

function handle_usuarios_list(PDO $pdo, array $config): void
{
    if (validar_api_key($config)) {
        json_response(['usuarios' => usuarios_listar_activos($pdo)], 200, $config);
        return;
    }

    $auth = require_auth($pdo, $config);
    require_gestion_role($auth['roles'], $config);

    json_response(['usuarios' => usuarios_listar_activos($pdo)], 200, $config);
}

// GET /areas — listado de áreas activas
function handle_areas_list(PDO $pdo, array $config): void
{
    if (validar_api_key($config)) {
        json_response(['areas' => areas_listar_activas($pdo)], 200, $config);
        return;
    }

    $auth = require_auth($pdo, $config);
    require_gestion_role($auth['roles'], $config);

    json_response(['areas' => areas_listar_activas($pdo)], 200, $config);
}

// GET /reportes/asistencia?year=2026&month=8&sede=&areaIds=guid,guid
// Endpoint server-to-server para SGI web y móvil. Requiere token general.
function handle_reporte_asistencia_sgi(PDO $pdo, array $config): void
{
    if (!validar_api_key($config)) {
        json_response(['message' => 'Error de seguridad AK-NOT_FOUND'], 401, $config);
        return;
    }

    $year = (int) ($_GET['year'] ?? date('Y'));
    $month = (int) ($_GET['month'] ?? date('n'));
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        json_response(['message' => 'year/month inválidos'], 400, $config);
        return;
    }

    $sede = trim((string) ($_GET['sede'] ?? ''));
    json_response([
        'reporte' => reportes_sgi_asistencia(
            $pdo,
            $year,
            $month,
            $sede !== '' ? $sede : null,
            reportes_sgi_area_ids_desde_query()
        ),
    ], 200, $config);
}

// GET /reportes/asistencia-detallada?year=2026&month=8&sede=&areaIds=guid,guid
// Endpoint server-to-server para SGI web. Requiere token general.
function handle_reporte_asistencia_detallada_sgi(PDO $pdo, array $config): void
{
    if (!validar_api_key($config)) {
        json_response(['message' => 'Error de seguridad AK-NOT_FOUND'], 401, $config);
        return;
    }

    $year = (int) ($_GET['year'] ?? date('Y'));
    $month = (int) ($_GET['month'] ?? date('n'));
    if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
        json_response(['message' => 'year/month inválidos'], 400, $config);
        return;
    }

    $sede = trim((string) ($_GET['sede'] ?? ''));
    json_response([
        'reporte' => reportes_sgi_asistencia_detallada(
            $pdo,
            $year,
            $month,
            $sede !== '' ? $sede : null,
            reportes_sgi_area_ids_desde_query()
        ),
    ], 200, $config);
}

// GET /reportes/horas-trabajadas?fechaInicio=YYYY-MM-DD&fechaFin=YYYY-MM-DD&tipoContratacionId=0&areaIds=guid,guid
// Endpoint server-to-server para SGI web y móvil. Requiere token general.
function handle_reporte_horas_trabajadas_sgi(PDO $pdo, array $config): void
{
    if (!validar_api_key($config)) {
        json_response(['message' => 'Error de seguridad AK-NOT_FOUND'], 401, $config);
        return;
    }

    $fechaInicio = trim((string) ($_GET['fechaInicio'] ?? ''));
    $fechaFin = trim((string) ($_GET['fechaFin'] ?? ''));
    if ($fechaInicio === '' || $fechaFin === '') {
        json_response(['message' => 'fechaInicio y fechaFin son requeridos'], 400, $config);
        return;
    }

    $tipoContratacionId = isset($_GET['tipoContratacionId']) ? (int) $_GET['tipoContratacionId'] : null;
    json_response([
        'reporte' => reportes_sgi_horas_trabajadas(
            $pdo,
            $fechaInicio,
            $fechaFin,
            $tipoContratacionId,
            reportes_sgi_area_ids_desde_query(),
            $config
        ),
    ], 200, $config);
}

// GET /novedades — bandeja del usuario logueado
function handle_novedades_list(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    require_any_role($auth['roles'], $config);
    novedades_require_tables($pdo, $config);

    $uid = (string) $auth['user']['Id'];
    if (filter_var($_GET['historial'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $anio = filter_var($_GET['anio'] ?? null, FILTER_VALIDATE_INT);
        $mes = filter_var($_GET['mes'] ?? null, FILTER_VALIDATE_INT);
        if ($anio === false || $anio < 1900 || $anio > 9998 || $mes === false || $mes < 1 || $mes > 12) {
            json_response(['message' => 'Seleccioná un mes y año válidos.'], 422, $config);
            return;
        }
        require_once __DIR__ . '/lib/novedades_historial.php';
        json_response(novedades_historial_mes($pdo, $uid, $anio, $mes), 200, $config);
        return;
    }
    json_response([
        'novedades' => novedades_listar_usuario($pdo, $uid),
        'noLeidas' => novedades_contar_no_leidas($pdo, $uid),
    ], 200, $config);
}

// POST /novedades — envía novedad (API key, no JWT de empleado)
function handle_novedades_enviar(PDO $pdo, array $config): void
{
    if (!validar_api_key($config)) {
        json_response(['message' => 'Error de seguridad AK-NOT_FOUND'], 401, $config);
        return;
    }
    novedades_require_tables($pdo, $config);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $titulo = trim((string) ($body['titulo'] ?? ''));
    $cuerpo = trim((string) ($body['cuerpo'] ?? ''));
    if ($titulo === '' || $cuerpo === '') {
        json_response(['message' => 'titulo y cuerpo son requeridos'], 400, $config);
        return;
    }

    $fromRaw = trim((string) ($body['from'] ?? $body['enviadoPorUsuarioId'] ?? ''));
    if ($fromRaw === '') {
        json_response(['message' => 'from (GUID del remitente) es requerido'], 400, $config);
        return;
    }
    if (novedades_usuario_activo($pdo, $fromRaw) === null) {
        json_response(['message' => 'Remitente (from) no encontrado o inactivo'], 400, $config);
        return;
    }

    $usuarioIdsRaw = $body['usuarioIds'] ?? null;
    $usuarioIds = null;
    if (is_array($usuarioIdsRaw) && $usuarioIdsRaw !== []) {
        $usuarioIds = novedades_resolve_destinatarios($pdo, $usuarioIdsRaw);
    } else {
        $usuarioIds = novedades_resolve_destinatarios($pdo, null);
    }

    if ($usuarioIds === []) {
        json_response(['message' => 'No hay destinatarios para la novedad'], 400, $config);
        return;
    }

    try {
        $result = novedades_crear_y_enviar($pdo, $titulo, $cuerpo, $fromRaw, $usuarioIds);
        json_response(['ok' => true] + $result, 201, $config);
    } catch (Throwable $e) {
        error_log('[novedades] No se pudo registrar la novedad: ' . $e->getMessage());
        json_response([
            'message' => 'No se pudo registrar la novedad',
            'detail' => $e->getMessage(),
        ], 500, $config);
    }
}

// POST /novedades/{id}/leida y fallback a archivos de auth salida
function handle_novedades_routes(PDO $pdo, array $config, string $path, string $method): void
{
    if ($method === 'GET' && preg_match('#^/novedades/([0-9a-f-]{36})$#i', $path, $m)) {
        $auth = require_auth($pdo, $config);
        require_any_role($auth['roles'], $config);
        novedades_require_tables($pdo, $config);

        $item = novedades_obtener_para_usuario($pdo, (string) $auth['user']['Id'], $m[1]);
        if ($item === null) {
            json_response(['message' => 'Novedad no encontrada'], 404, $config);
            return;
        }
        json_response(['novedad' => $item], 200, $config);
        return;
    }

    if ($method === 'POST' && preg_match('#^/novedades/([0-9a-f-]{36})/leida$#i', $path, $m)) {
        $auth = require_auth($pdo, $config);
        require_any_role($auth['roles'], $config);
        novedades_require_tables($pdo, $config);

        $ok = novedades_marcar_leida($pdo, (string) $auth['user']['Id'], $m[1]);
        json_response(['ok' => $ok], $ok ? 200 : 404, $config);
        return;
    }

    handle_autorizacion_archivo($pdo, $config, $path, $method);
}

// Calculo comun de horas extras para consumidores web y movil.
function handle_reporte_horas_extras_diarias_sgi(PDO $pdo, array $config): void
{
    if (!validar_api_key($config)) {
        json_response(['message' => 'Error de seguridad AK-NOT_FOUND'], 401, $config);
        return;
    }
    $desde = reportes_sgi_parse_fecha((string) ($_GET['fechaInicio'] ?? ''));
    $hasta = reportes_sgi_parse_fecha((string) ($_GET['fechaFin'] ?? ''));
    if ($desde === null || $hasta === null || $desde > $hasta) {
        json_response(['message' => 'Periodo invalido'], 400, $config);
        return;
    }
    json_response(['reporte' => ['empleados' => horas_extra_motivo_calcular_diarias(
        $pdo, $config, $desde, $hasta,
        isset($_GET['tipoContratacionId']) ? (int) $_GET['tipoContratacionId'] : null,
        reportes_sgi_area_ids_desde_query()
    )]], 200, $config);
}
