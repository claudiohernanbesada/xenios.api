<?php
declare(strict_types=1);

// Front controller: todas las rutas de la API móvil pasan por acá
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/avatar.php';
require_once __DIR__ . '/lib/auth_salida_file.php';

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
        $method === 'GET' && $path === '/auth/me' => handle_me($pdo, $config),
        $method === 'POST' && $path === '/auth/cambiar-clave' => handle_cambiar_clave($pdo, $config),
        $method === 'POST' && $path === '/auth/avatar' => handle_avatar_upload($pdo, $config),
        $method === 'DELETE' && $path === '/auth/avatar' => handle_avatar_delete($pdo, $config),
        $method === 'GET' && $path === '/auth/avatar/imagen' => handle_avatar_imagen($pdo, $config),
        $method === 'GET' && $path === '/fichadas' => handle_fichadas($pdo, $config),
        $method === 'GET' && $path === '/autorizaciones-salida' => handle_autorizaciones($pdo, $config),
        default => handle_autorizacion_archivo($pdo, $config, $path, $method),
    };
// Cualquier excepción sin atrapar en un handler → 500 genérico
} catch (Throwable $e) {
    json_response(['message' => 'Error interno del servidor'], 500, $config);
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
    $personal = fetch_personal_by_mail($pdo, (string) $auth['user']['Mail']);

    if ($personal === null) {
        json_response(['autorizaciones' => []], 200, $config);
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT a.Id, a.Fecha, a.HoraSalida, a.HoraRegreso,
                m.MotivoAuth, a.MotivoDetalle, a.AuthFile
         FROM AuthSalida a
         INNER JOIN AuthSalidaMotivos m ON m.Id = a.MotivoId
         WHERE a.Estado = 1 AND a.PersonalId = :pid
         ORDER BY a.Fecha DESC, a.HoraSalida DESC'
    );
    $stmt->execute(['pid' => $personal['Id']]);
    $rows = $stmt->fetchAll();

    $autorizaciones = array_map(static function (array $row): array {
        // Fecha a dd/mm/yyyy y flags de archivo adjunto para la app
        $fechaRaw = (string) $row['Fecha'];
        $fechaFmt = $fechaRaw;
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $fechaRaw);
        if ($parsed) {
            $fechaFmt = $parsed->format('d/m/Y');
        }

        $authFile = $row['AuthFile'] ?? null;
        $tiene = $authFile !== null && $authFile !== '';

        return [
            'id' => (string) $row['Id'],
            'fecha' => $fechaFmt,
            'horaSalida' => substr((string) $row['HoraSalida'], 0, 5),
            'horaRegreso' => substr((string) $row['HoraRegreso'], 0, 5),
            'motivo' => (string) $row['MotivoAuth'],
            'motivoDetalle' => $row['MotivoDetalle'] ?: null,
            'tieneArchivo' => $tiene,
            'nombreArchivo' => $tiene ? basename((string) $authFile) : null,
        ];
    }, $rows);

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
