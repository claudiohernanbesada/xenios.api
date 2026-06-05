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
require_once __DIR__ . '/lib/password_reset.php';
require_once __DIR__ . '/lib/vacaciones.php';
require_once __DIR__ . '/lib/auth_salida_list.php';
require_once __DIR__ . '/lib/usuarios.php';

$config = require __DIR__ . '/config.php';
apply_cors($config);

// Preflight CORS — el browser manda OPTIONS antes del POST/DELETE real
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    apply_cors($config);
    http_response_code(204);
    exit;
}

// Si MySQL no responde, cortamos acá con 500
try {
    $pdo = db_connect($config);
} catch (Throwable $e) {
    json_response(['message' => 'Error de conexión a base de datos'], 500, $config);
    exit;
}

// Path limpio y método HTTP del request entrante
$path = current_api_path($config);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Enrutador: método + path → handler correspondiente
try {
    match (true) {
        $method === 'GET' && $path === '/health' => json_response(['ok' => true, 'service' => 'sgi-movil'], 200, $config),
        $method === 'POST' && $path === '/auth/login' => handle_login($pdo, $config),
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
        $method === 'GET' && $path === '/asistencia' => handle_asistencia($pdo, $config),
        $method === 'GET' && $path === '/vacaciones' => handle_vacaciones($pdo, $config),
        $method === 'POST' && $path === '/push/registrar-token' => handle_push_registrar_token($pdo, $config),
        $method === 'GET' && $path === '/novedades' => handle_novedades_list($pdo, $config),
        $method === 'GET' && $path === '/usuarios' => handle_usuarios_list($pdo, $config),
        $method === 'POST' && $path === '/novedades' => handle_novedades_enviar($pdo, $config),
        default => handle_novedades_routes($pdo, $config, $path, $method),
    };
// Cualquier excepción sin atrapar en un handler → 500 genérico
} catch (Throwable $e) {
    json_response(['message' => 'Error interno del servidor'], 500, $config);
}

// POST /auth/recuperar-clave — envía mail con enlace (respuesta genérica)
function handle_recuperar_clave_solicitud(PDO $pdo, array $config): void
{
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $correo = strtolower(trim((string) ($body['correo'] ?? '')));
    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        json_response(['message' => 'Ingresá un correo electrónico válido'], 400, $config);
        return;
    }

    $result = password_reset_request($pdo, $config, $correo);
    $code = ($result['ok'] ?? false) ? 200 : 503;
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
    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $correo = strtolower(trim((string) ($body['correo'] ?? '')));
    $clave = (string) ($body['clave'] ?? '');

    if ($correo === '' || $clave === '') {
        json_response(['message' => 'Correo y contraseña requeridos'], 400, $config);
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT Id, Nombre, Apellido, Mail, Estado, ImgPerfil
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

    $roles = fetch_user_roles($pdo, (string) $user['Id']);
    $personal = fetch_personal_by_mail($pdo, $correo);

    $token = issue_token(
        (string) $user['Id'],
        $correo,
        $config['jwt_secret'],
        (int) $config['jwt_ttl_seconds']
    );

    json_response([
        'token' => $token,
        'usuario' => build_usuario_response($user, $roles, $personal, $config),
    ], 200, $config);
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
    $nueva = (string) ($body['nuevaClave'] ?? '');
    $confirmacion = (string) ($body['confirmacion'] ?? '');

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
        'UPDATE Usuarios SET Passwd = :passwd, dateUpdate = NOW() WHERE Id = :id'
    );
    $stmt->execute([
        'passwd' => sha256_password($nueva),
        'id' => $auth['user']['Id'],
    ]);

    json_response(['ok' => true], 200, $config);
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

    $personal = fetch_personal_empleado_completo($pdo, (string) $auth['user']['Mail']);
    if ($personal === null) {
        json_response([
            'desde' => $desde,
            'hasta' => $hasta,
            'dias' => [],
            'resumen' => horas_trabajadas_build_resumen($pdo, ['Id' => '', 'Legajo' => 0], $desde, $hasta),
        ], 200, $config);
        return;
    }

    json_response([
        'desde' => $desde,
        'hasta' => $hasta,
        'dias' => asistencia_build_calendario($pdo, $personal, $desde, $hasta),
        'resumen' => horas_trabajadas_build_resumen($pdo, $personal, $desde, $hasta),
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

// POST /push/registrar-token — guarda token Expo del dispositivo
function handle_push_registrar_token(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    require_any_role($auth['roles'], $config);
    novedades_require_tables($pdo, $config);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $token = trim((string) ($body['expoPushToken'] ?? ''));
    if ($token === '') {
        json_response(['message' => 'expoPushToken requerido'], 400, $config);
        return;
    }

    novedades_registrar_token(
        $pdo,
        (string) $auth['user']['Id'],
        $token,
        isset($body['plataforma']) ? (string) $body['plataforma'] : null
    );

    json_response(['ok' => true], 200, $config);
}

// GET /usuarios — listado de usuarios activos con sus áreas
function handle_usuarios_list(PDO $pdo, array $config): void
{
    if (novedades_validar_api_key($config)) {
        json_response(['usuarios' => usuarios_listar_activos($pdo)], 200, $config);
        return;
    }

    $auth = require_auth($pdo, $config);
    require_gestion_role($auth['roles'], $config);

    json_response(['usuarios' => usuarios_listar_activos($pdo)], 200, $config);
}

// GET /novedades — bandeja del usuario logueado
function handle_novedades_list(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    require_any_role($auth['roles'], $config);
    novedades_require_tables($pdo, $config);

    $uid = (string) $auth['user']['Id'];
    json_response([
        'novedades' => novedades_listar_usuario($pdo, $uid),
        'noLeidas' => novedades_contar_no_leidas($pdo, $uid),
    ], 200, $config);
}

// POST /novedades — envía novedad (API key, no JWT de empleado)
function handle_novedades_enviar(PDO $pdo, array $config): void
{
    if (!novedades_validar_api_key($config)) {
        json_response(['message' => 'API key inválida'], 401, $config);
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
        json_response(['message' => 'No se pudo registrar la novedad'], 500, $config);
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
