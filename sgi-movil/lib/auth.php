<?php
declare(strict_types=1);

// Auth: JWT casero, roles, permisos y middleware require_auth
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/doble_factor.php';

// Hashea la clave igual que el SGI web (SHA-256 plano)
function sha256_password(string $plain): string
{
    return hash('sha256', $plain);
}

// Valida reglas de contraseña fuerte; devuelve mensaje de error o null si está ok
function validate_password_policy(string $password): ?string
{
    if (strlen($password) < 10) {
        return 'La contraseña debe tener al menos 10 caracteres';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Debe incluir al menos una mayúscula';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Debe incluir al menos una minúscula';
    }
    if (preg_match_all('/\d/', $password) < 2) {
        return 'Debe incluir al menos dos números';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Debe incluir al menos un carácter especial';
    }
    return null;
}

// Arma un JWT casero: payload en base64 + firma HMAC
function issue_token(string $userId, string $mail, string $secret, int $ttl, ?string $mfaVersion = null): string
{
    if (strlen($secret) < 32) throw new RuntimeException('Configurar SGI_JWT_SECRET (mínimo 32 caracteres).');
    $payload = [
        'sub' => $userId,
        'mail' => $mail,
        'exp' => time() + $ttl,
    ];
    if ($mfaVersion !== null) {
        $payload['mfa'] = true;
        $payload['mfaVersion'] = $mfaVersion;
    }
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $b64 = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $b64, $secret);
    return $b64 . '.' . $sig;
}

// Verifica firma y expiración del token; null si no sirve
function verify_token(string $token, string $secret): ?array
{
    if (strlen($secret) < 32) return null;
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }
    [$b64, $sig] = $parts;
    $expected = hash_hmac('sha256', $b64, $secret);
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    $json = base64_decode(strtr($b64, '-_', '+/'), true);
    if ($json === false) {
        return null;
    }
    $data = json_decode($json, true);
    if (!is_array($data) || (int) ($data['exp'] ?? 0) < time()) {
        return null;
    }
    return $data;
}

// Saca el Bearer del header (con parches para WAMP y query string)
function bearer_token(): ?string
{
    $header = (string) (
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? ''
    );

    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }

    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $header = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
        }
    }

    if (preg_match('/Bearer\s+(\S+)/i', $header, $m)) {
        return $m[1];
    }

    // Plan B: WAMP a veces no pasa Authorization, la app manda X-Auth-Token
    $xToken = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if (is_string($xToken) && $xToken !== '') {
        return $xToken;
    }

    if (!empty($_GET['token']) && is_string($_GET['token'])) {
        return $_GET['token'];
    }

    return null;
}

// True si tiene al menos un rol con nombre no vacío
function user_has_any_role(array $roles): bool
{
    foreach ($roles as $role) {
        if (trim((string) $role) !== '') {
            return true;
        }
    }
    return false;
}

// Corta con 403 si el usuario no tiene ningún rol asignado
function require_any_role(array $roles, array $config): void
{
    if (!user_has_any_role($roles)) {
        json_response([
            'message' => 'Sin roles asignados. Contacte al administrador del sistema.',
        ], 403, $config);
        exit;
    }
}

// Chequea si entre los roles está EMPLEADO
function user_has_empleado_role(array $roles): bool
{
    foreach ($roles as $role) {
        if (strtoupper((string) $role) === 'EMPLEADO') {
            return true;
        }
    }
    return false;
}

// Solo empleados pueden fichadas, auths de salida, etc.
function require_empleado_role(array $roles, array $config): void
{
    require_any_role($roles, $config);
    if (!user_has_empleado_role($roles)) {
        json_response([
            'message' => 'Esta función está disponible solo para usuarios con rol EMPLEADO',
        ], 403, $config);
        exit;
    }
}

// Usuarios con solo rol EMPLEADO no pueden consultas de gestión
function require_gestion_role(array $roles, array $config): void
{
    require_any_role($roles, $config);

    $upper = array_map('strtoupper', $roles);
    $onlyEmpleado = in_array('EMPLEADO', $upper, true)
        && count(array_filter($upper, static fn ($r) => $r !== 'EMPLEADO')) === 0;

    if ($onlyEmpleado) {
        json_response(['message' => 'Sin permisos de gestión para su rol'], 403, $config);
        exit;
    }
}

// Empleado puede subir avatar pero no borrarlo (solo POST en /auth/avatar)
function empleado_may_write_avatar(string $path, string $method): bool
{
    if ($path !== '/auth/avatar') {
        return false;
    }
    return $method === 'POST';
}

// POSTs permitidos para rol EMPLEADO puro
function empleado_may_post(string $path): bool
{
    if (in_array($path, ['/auth/cambiar-clave', '/auth/avatar', '/push/registrar-token', '/push/fichadas/evento', '/notas-traslado/scan', '/inout-seguridad/leida'], true)) {
        return true;
    }

    return (bool) preg_match('#^/notas-traslado/[0-9a-f-]{36}/recibido$#i', $path);
}

// Empleado solo lectura salvo login, cambio de clave y subir avatar
function enforce_method_for_roles(array $roles, string $method, string $path, array $config): void
{
    $upper = array_map('strtoupper', $roles);
    $onlyEmpleado = in_array('EMPLEADO', $upper, true)
        && count(array_filter($upper, fn ($r) => $r !== 'EMPLEADO')) === 0;

    if (!$onlyEmpleado) {
        return;
    }
    if ($method === 'GET' || $method === 'HEAD' || $method === 'OPTIONS') {
        return;
    }
    if ($method === 'POST' && ($path === '/auth/login' || empleado_may_post($path))) {
        return;
    }
    if (empleado_may_write_avatar($path, $method)) {
        return;
    }

    json_response(['message' => 'Sin permisos de gestión para su rol'], 403, $config);
    exit;
}

// Path relativo sin el prefijo /sgi-movil
function current_api_path(array $config): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    // Rewrite a index.php deja /sgi-movil/index.php/novedades — normalizar
    $uri = preg_replace('#/index\.php(?=/|$)#', '', $uri) ?: '/';
    $base = rtrim($config['api_base_path'] ?? '/sgi-movil', '/');
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base)) ?: '/';
        while ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base)) ?: '/';
        }
    }
    return rtrim($uri, '/') ?: '/';
}

// Roles del usuario desde RolUsuario + Roles
function fetch_user_roles(PDO $pdo, string $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT r.RolName FROM Roles r
         INNER JOIN RolUsuario ru ON ru.RolesId = r.Id
         WHERE ru.UsuariosId = :uid'
    );
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

// Ficha de Personal activo vinculada al mail corporativo o personal
function fetch_personal_by_mail(PDO $pdo, string $mail): ?array
{
    $stmt = $pdo->prepare(
        'SELECT Id, Legajo, Nombre, Apellido, Foto FROM Personal
         WHERE Estado = 1
           AND (
             LOWER(TRIM(EmailCorporativo)) = LOWER(:mail)
             OR LOWER(TRIM(Email)) = LOWER(:mail)
           )
         LIMIT 1'
    );
    $stmt->execute(['mail' => strtolower(trim($mail))]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Personal del empleado logueado con campos para reportes de asistencia/horas. */
function fetch_personal_empleado_completo(PDO $pdo, string $mail): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.Id, p.Legajo, p.Nombre, p.Apellido, p.Estado, p.FechaBaja,
                p.HorarioIngreso, p.HorarioSalida, a.Nombre AS AreaNombre
         FROM Personal p
         LEFT JOIN Area a ON a.Id = p.ArealId
         WHERE p.Estado IN (1, 2)
           AND (
             LOWER(TRIM(p.EmailCorporativo)) = LOWER(:mail)
             OR LOWER(TRIM(p.Email)) = LOWER(:mail)
           )
         LIMIT 1'
    );
    $stmt->execute(['mail' => strtolower(trim($mail))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// Objeto usuario para la app móvil.
// Avatar = Usuarios.ImgPerfil (repo avatar_usuario). NO usar Personal.Foto (foto de legajo).
function build_usuario_response(array $user, array $roles, ?array $personal, array $config): array
{
    require_once __DIR__ . '/avatar.php';
    $avatarUrl = resolve_avatar_url($user, $config);

    return [
        'id' => $user['Id'],
        'nombre' => $user['Nombre'],
        'apellido' => $user['Apellido'],
        'mail' => $user['Mail'],
        'roles' => $roles,
        'imgPerfil' => $user['ImgPerfil'] ?? null,
        'avatarUrl' => $avatarUrl,
        'personalId' => $personal['Id'] ?? null,
        'legajo' => isset($personal['Legajo']) ? (int) $personal['Legajo'] : null,
    ];
}

// Legajo a veces está con y sin ceros a la izquierda en FichadasGlobal
function normalizar_legajo_claves(int $legajo): array
{
    return [
        (string) $legajo,
        str_pad((string) $legajo, 4, '0', STR_PAD_LEFT),
    ];
}

function config_api_key(array $config): string
{
    return (string) ($config['api_key'] ?? $config['novedades_api_key'] ?? '');
}

/** Valida la clave de API enviada en X-API-KEY (o X-Novedades-Api-Key por compatibilidad). */
function validar_api_key(array $config): bool
{
    $expected = config_api_key($config);
    if ($expected === '') {
        return false;
    }

    if (function_exists('sgi_api_token_recibido')) {
        $provided = sgi_api_token_recibido();
    } else {
        $provided = (string) (
            $_SERVER['HTTP_X_API_KEY']
            ?? $_SERVER['HTTP_X_NOVEDADES_API_KEY']
            ?? ''
        );
    }

    return $provided !== '' && hash_equals($expected, $provided);
}

// Middleware: token válido + usuario activo + permisos según rol
function require_api_key(array $config): void
{
    if (!validar_api_key($config)) {
        json_response(['message' => 'Error de seguridad AK-NOT_FOUND'], 401, $config);
        exit;
    }
}

function require_auth(PDO $pdo, array $config): array
{
    $token = bearer_token();
    if ($token === null) {
        json_response(['message' => 'Token requerido'], 401, $config);
        exit;
    }
    $payload = verify_token($token, $config['jwt_secret']);
    if ($payload === null) {
        json_response(['message' => 'Token inválido o expirado'], 401, $config);
        exit;
    }
    $userId = (string) ($payload['sub'] ?? '');
    if ($userId === '') {
        json_response(['message' => 'Token inválido'], 401, $config);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT Id, Nombre, Apellido, Mail, Estado, ImgPerfil, DepositoId,
                RequiereDobleFactor, DobleFactorVersion, (DobleFactorSecreto IS NOT NULL) AS DobleFactorConfigurado
         FROM Usuarios WHERE Id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if (!$user || (int) $user['Estado'] !== 1) {
        json_response(['message' => 'Usuario no disponible'], 403, $config);
        exit;
    }

    if (!doble_factor_token_valido($user, $payload)) {
        json_response(['message' => 'La sesión requiere verificar el doble factor. Iniciá sesión nuevamente.'], 401, $config);
        exit;
    }
    $roles = fetch_user_roles($pdo, $userId);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = current_api_path($config);
    enforce_method_for_roles($roles, $method, $path, $config);

    $stmtActivity = $pdo->prepare(
        'UPDATE Usuarios SET LastLogin = NOW(), Plataforma = :plataforma WHERE Id = :id'
    );
    $stmtActivity->execute([
        'id' => $userId,
        'plataforma' => 'mobil',
    ]);

    return [
        'token_payload' => $payload,
        'user' => $user,
        'roles' => $roles,
    ];
}

function require_auth_or_web_bridge(PDO $pdo, array $config): array
{
    if (bearer_token() !== null) {
        return require_auth($pdo, $config);
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $userId = trim((string) ($headers['X-SGI-WEB-USER-ID'] ?? $_SERVER['HTTP_X_SGI_WEB_USER_ID'] ?? ''));
    $timestamp = trim((string) ($headers['X-SGI-WEB-TS'] ?? $_SERVER['HTTP_X_SGI_WEB_TS'] ?? ''));
    $signature = trim((string) ($headers['X-SGI-WEB-SIGNATURE'] ?? $_SERVER['HTTP_X_SGI_WEB_SIGNATURE'] ?? ''));

    if ($userId === '' || $timestamp === '' || $signature === '' || !validar_api_key($config)) {
        json_response(['message' => 'Token requerido'], 401, $config);
        exit;
    }

    if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
        json_response(['message' => 'Firma web expirada'], 401, $config);
        exit;
    }

    $secret = config_api_key($config);
    $expected = hash_hmac('sha256', $userId . '|' . $timestamp, $secret);
    if ($secret === '' || !hash_equals($expected, $signature)) {
        json_response(['message' => 'Firma web inválida'], 401, $config);
        exit;
    }

    $stmt = $pdo->prepare(
        'SELECT Id, Nombre, Apellido, Mail, Estado, ImgPerfil, DepositoId,
                RequiereDobleFactor, DobleFactorVersion, (DobleFactorSecreto IS NOT NULL) AS DobleFactorConfigurado
         FROM Usuarios WHERE Id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();
    if (!$user || (int) $user['Estado'] !== 1) {
        json_response(['message' => 'Usuario no disponible'], 403, $config);
        exit;
    }

    if (!doble_factor_puente_web_valido($config, $user, $timestamp)) {
        json_response(['message' => 'El acceso web requiere una verificación de doble factor válida.'], 401, $config);
        exit;
    }
    $roles = fetch_user_roles($pdo, $userId);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = current_api_path($config);
    enforce_method_for_roles($roles, $method, $path, $config);

    return [
        'token_payload' => ['sub' => $userId, 'source' => 'web'],
        'user' => $user,
        'roles' => $roles,
    ];
}
