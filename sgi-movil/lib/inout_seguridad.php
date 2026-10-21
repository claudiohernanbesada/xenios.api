<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/novedades.php';
require_once __DIR__ . '/push_native.php';

// PCRE maneja UTF-8 sin requerir la extensión opcional mbstring del servidor.
function inout_text_length(string $value): int
{
    $length = preg_match_all('/./us', $value);
    if ($length === false) throw new InvalidArgumentException('Texto UTF-8 inválido.');
    return $length;
}

function inout_text_cut(string $value, int $maxBytes): string
{
    $cut = substr($value, 0, $maxBytes);
    // Si el límite parte un carácter, quitar solo sus bytes incompletos.
    while ($cut !== '' && preg_match('//u', $cut) !== 1) $cut = substr($cut, 0, -1);
    return $cut;
}

function inout_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function inout_error(string $message, array $config, int $status = 422): never
{
    json_response(['message' => $message], $status, $config);
    exit;
}

function inout_sede(PDO $pdo, array $user, array $config): string
{
    $stmt = $pdo->prepare('SELECT Nombre FROM Depositos WHERE Id = ? AND Estado = 1');
    $stmt->execute([$user['DepositoId'] ?? null]);
    $nombre = strtolower(trim((string) $stmt->fetchColumn()));
    if (str_contains($nombre, 'cardales')) return 'Cardales';
    if (str_contains($nombre, 'san fernando')) return 'San Fernando';
    inout_error('Tu usuario debe tener asignada la sede Cardales o San Fernando.', $config, 403);
}

function inout_require_permission(array $auth, array $config): void
{
    $roles = array_map('strtoupper', $auth['roles']);
    if (array_intersect($roles, ['SEGURIDAD_INOUT', 'ADMIN', 'SYSADMIN']) === []) {
        inout_error('Sin permiso para registrar o consultar movimientos de seguridad.', $config, 403);
    }
}

function inout_notification(array $rows): array
{
    $ingreso = $rows[0]['Movimiento'] === 'Ingreso';
    return [
        'transaccionId' => $rows[0]['TransaccionId'],
        'title' => $ingreso ? 'Ingreso de Invitado' : 'Egreso de Invitado',
        'body' => ($ingreso ? 'Esta ingresando a la empresa: ' : 'Esta saliendo de la empresa: ')
            . implode(', ', array_map(static fn(array $r): string => $r['Nombre'] . ' ' . $r['Apellido'], $rows)),
        'personas' => array_map(static fn(array $r): array => ['nombre' => $r['Nombre'], 'apellido' => $r['Apellido']], $rows),
    ];
}

// La notificación queda persistida junto a los movimientos; el destinatario puede
// recuperarla incluso si el proveedor push o su dispositivo estaban desconectados.
function inout_send_notification(PDO $pdo, string $transaccionId): array
{
    $stmt = $pdo->prepare('SELECT * FROM InOutSeguridad WHERE TransaccionId = ? AND Estado = 1 AND UsuarioVisitaId IS NOT NULL AND NotificacionLeidaEn IS NULL ORDER BY Apellido, Nombre');
    $stmt->execute([$transaccionId]);
    $rows = $stmt->fetchAll();
    if ($rows === []) return ['sent' => 0, 'ok' => true];
    $notification = inout_notification($rows);
    // FCM/APNs limitan el payload a 4 KB; el detalle completo se recupera por API.
    $pushBody = inout_text_cut($notification['body'], 2200);
    if ($pushBody !== $notification['body']) $pushBody .= '… Ver listado completo en la app.';
    $tokens = novedades_tokens_por_usuarios($pdo, [$rows[0]['UsuarioVisitaId']]);
    $messages = [];
    foreach ($tokens as $token) {
        if (!push_native_token_valido($token['token'], $token['plataforma'])) continue;
        $messages[] = $token + [
            'title' => $notification['title'], 'body' => $pushBody,
            'channelId' => 'visitas-v1', 'sound' => 'campana.wav',
            'data' => ['tipo' => 'inoutSeguridad', 'transaccionId' => $transaccionId],
        ];
    }
    $result = push_native_send_batch($messages);
    if ($result['sent'] > 0) {
        $pdo->prepare('UPDATE InOutSeguridad SET NotificacionEnviadaEn = NOW() WHERE TransaccionId = ?')->execute([$transaccionId]);
    }
    return ['sent' => $result['sent'], 'ok' => $result['ok'] && $messages !== []];
}

function handle_inout_seguridad(PDO $pdo, array $config, string $path, string $method): void
{
    $notificacion = in_array($path, ['/inout-seguridad/notificaciones', '/inout-seguridad/leida'], true);
    $auth = $notificacion ? require_auth_or_web_bridge($pdo, $config) : require_auth($pdo, $config);
    $uid = $auth['user']['Id'];

    // Las visitas están dirigidas al usuario: no requieren permiso de portería.
    if ($path === '/inout-seguridad/notificaciones' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT * FROM InOutSeguridad WHERE UsuarioVisitaId = ? AND Estado = 1 AND NotificacionLeidaEn IS NULL ORDER BY DateAdd, Id');
        $stmt->execute([$uid]);
        $groups = [];
        foreach ($stmt->fetchAll() as $row) $groups[$row['TransaccionId']][] = $row;
        json_response(['notificaciones' => array_values(array_map('inout_notification', $groups))], 200, $config);
        return;
    }
    if ($path === '/inout-seguridad/leida' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $pdo->prepare('UPDATE InOutSeguridad SET NotificacionLeidaEn = COALESCE(NotificacionLeidaEn, NOW()) WHERE TransaccionId = ? AND UsuarioVisitaId = ? AND Estado = 1')
            ->execute([(string) ($body['transaccionId'] ?? ''), $uid]);
        json_response(['ok' => true], 200, $config);
        return;
    }

    inout_require_permission($auth, $config);
    $sede = inout_sede($pdo, $auth['user'], $config);
    if ($path === '/inout-seguridad/opciones' && $method === 'GET') {
        $recurso = (string) ($_GET['recurso'] ?? 'motivos');
        $q = '%' . trim((string) ($_GET['q'] ?? '')) . '%';
        $sql = match ($recurso) {
            'empresas' => 'SELECT Id AS id, Nombre AS label FROM Empresa WHERE Estado = 1 AND Nombre LIKE ? ORDER BY Nombre LIMIT 100',
            'usuarios' => "SELECT Id AS id, CONCAT(Nombre, ' ', Apellido) AS label FROM Usuarios WHERE Estado = 1 AND CONCAT(Nombre, ' ', Apellido, ' ', Mail) LIKE ? ORDER BY Apellido, Nombre LIMIT 100",
            'motivos' => 'SELECT Id AS id, Nombre AS label FROM InOutSeguridadMotivo WHERE Estado = 1 AND Nombre LIKE ? ORDER BY Nombre LIMIT 100',
            default => null,
        };
        if ($sql === null) inout_error('Catálogo inválido.', $config);
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$q]);
        json_response(['opciones' => $stmt->fetchAll(), 'sede' => $sede], 200, $config);
        return;
    }
    if ($path === '/inout-seguridad/personal' && $method === 'GET') {
        $stmt = $pdo->prepare('SELECT Id AS id, Nombre AS nombre, Apellido AS apellido, Legajo AS legajo FROM Personal WHERE Legajo = ? AND Estado = 1');
        $stmt->execute([(int) ($_GET['legajo'] ?? 0)]);
        $rows = $stmt->fetchAll();
        if (count($rows) !== 1) inout_error('No se encontró un empleado activo único para ese legajo.', $config, 404);
        json_response(['personal' => $rows[0]], 200, $config);
        return;
    }
    if ($path === '/inout-seguridad' && $method === 'GET') {
        $page = max(1, min(1000000, (int) ($_GET['pagina'] ?? 1)));
        $count = $pdo->prepare('SELECT COUNT(*) FROM InOutSeguridad WHERE Sede = ? AND Estado = 1');
        $count->execute([$sede]);
        $offset = ($page - 1) * 20;
        $stmt = $pdo->prepare("SELECT Id AS id, Movimiento AS movimiento, DATE_FORMAT(Horario, '%H:%i') AS horario, Nombre AS nombre, Apellido AS apellido, EmpresaNombre AS empresa, DATE_FORMAT(DateAdd, '%d/%m/%Y') AS fecha FROM InOutSeguridad WHERE Sede = ? AND Estado = 1 ORDER BY DateAdd DESC, Id DESC LIMIT 20 OFFSET $offset");
        $stmt->execute([$sede]);
        json_response(['registros' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn(), 'pagina' => $page, 'sede' => $sede], 200, $config);
        return;
    }
    if ($path !== '/inout-seguridad' || $method !== 'POST') inout_error('Ruta no encontrada', $config, 404);

    $b = json_decode(file_get_contents('php://input'), true);
    if (!is_array($b)) inout_error('Datos inválidos.', $config);
    $tipo = $b['tipoPersona'] ?? '';
    $movimiento = $b['movimiento'] ?? '';
    $hora = $b['horario'] ?? '';
    $transaction = (string) ($b['transaccionId'] ?? '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $transaction)) inout_error('Identificador de carga inválido.', $config);
    if (!in_array($tipo, ['RAEI', 'Externo', 'Invitado'], true) || !in_array($movimiento, ['Ingreso', 'Egreso'], true)) inout_error('Tipo de persona o movimiento inválido.', $config);
    if (!is_string($hora) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $hora)) inout_error('Ingresá el horario en formato HH:mm.', $config);
    $nota = trim((string) ($b['nota'] ?? ''));
    if (inout_text_length($nota) > 2000) inout_error('La nota admite hasta 2000 caracteres.', $config);
    $stmt = $pdo->prepare('SELECT Id FROM InOutSeguridadMotivo WHERE Id = ? AND Estado = 1');
    $stmt->execute([$b['motivoId'] ?? '']);
    if (!$stmt->fetchColumn()) inout_error('Seleccioná un motivo activo.', $config);
    $usuarioVisita = $tipo === 'RAEI' ? null : ($b['usuarioVisitaId'] ?? null);
    if ($tipo === 'Invitado' && !$usuarioVisita) inout_error('Para invitados es obligatorio seleccionar a quién visitan.', $config);
    if ($usuarioVisita) {
        $stmt = $pdo->prepare('SELECT Id FROM Usuarios WHERE Id = ? AND Estado = 1');
        $stmt->execute([$usuarioVisita]);
        if (!$stmt->fetchColumn()) inout_error('El usuario visitado no está disponible.', $config);
    }
    $personalId = null; $legajo = null; $empresaId = null; $empresa = 'RAEI';
    if ($tipo === 'RAEI') {
        $stmt = $pdo->prepare('SELECT Id, Legajo, Nombre AS nombre, Apellido AS apellido, Dni AS dni FROM Personal WHERE Legajo = ? AND Estado = 1');
        $stmt->execute([(int) ($b['legajo'] ?? 0)]);
        $found = $stmt->fetchAll();
        if (count($found) !== 1) inout_error('Legajo no disponible o ambiguo.', $config);
        $personas = $found;
        $personalId = $found[0]['Id']; $legajo = $found[0]['Legajo'];
    } else {
        $empresaId = ($b['empresaId'] ?? null) ?: null;
        if ($empresaId) {
            $stmt = $pdo->prepare('SELECT Nombre FROM Empresa WHERE Id = ? AND Estado = 1');
            $stmt->execute([$empresaId]);
            $empresa = $stmt->fetchColumn();
            if (!$empresa) inout_error('Empresa no disponible.', $config);
        } else {
            $empresa = trim((string) ($b['empresaOtra'] ?? ''));
            if ($empresa === '' || inout_text_length($empresa) > 100) inout_error('Ingresá el nombre de la empresa (hasta 100 caracteres).', $config);
        }
        $personas = $b['personas'] ?? [];
        if (!is_array($personas) || count($personas) < 1 || count($personas) > 50) inout_error('Agregá entre 1 y 50 personas.', $config);
        $dnis = [];
        foreach ($personas as &$p) {
            if (!is_array($p)) inout_error('Persona inválida.', $config);
            foreach (['nombre', 'apellido', 'dni'] as $key) $p[$key] = trim((string) ($p[$key] ?? ''));
            if ($p['nombre'] === '' || $p['apellido'] === '' || inout_text_length($p['nombre']) > 100 || inout_text_length($p['apellido']) > 100 || !preg_match('/^\d{7,8}$/', $p['dni'])) inout_error('Revisá nombre, apellido y DNI de las personas.', $config);
            if (isset($dnis[$p['dni']])) inout_error('Hay un DNI repetido en la carga.', $config);
            $dnis[$p['dni']] = true;
        }
        unset($p);
    }
    // El mismo identificador permite reintentar ante cortes de red sin duplicar personas.
    $stmt = $pdo->prepare('SELECT UserAdd, COUNT(*) AS Cantidad FROM InOutSeguridad WHERE TransaccionId = ? GROUP BY UserAdd');
    $stmt->execute([$transaction]);
    $existing = $stmt->fetch();
    if ($existing) {
        if ($existing['UserAdd'] !== $uid) inout_error('Identificador ya utilizado.', $config, 409);
        json_response(['ok' => true, 'cantidad' => (int) $existing['Cantidad'], 'transaccionId' => $transaction, 'reintento' => true], 200, $config);
        return;
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO InOutSeguridad (Id, TransaccionId, TipoPersona, Movimiento, PersonalId, Legajo, Nombre, Apellido, Dni, EmpresaId, EmpresaNombre, Horario, MotivoId, UsuarioVisitaId, Nota, Sede, DateAdd, UserAdd, Estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 1)');
        foreach ($personas as $p) $stmt->execute([inout_uuid(), $transaction, $tipo, $movimiento, $personalId, $legajo, $p['nombre'], $p['apellido'], $p['dni'], $empresaId, $empresa, $hora . ':00', $b['motivoId'], $usuarioVisita ?: null, $nota ?: null, $sede, $uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM InOutSeguridad WHERE TransaccionId = ? AND UserAdd = ?');
            $check->execute([$transaction, $uid]);
            $count = (int) $check->fetchColumn();
            if ($count > 0) {
                json_response(['ok' => true, 'cantidad' => $count, 'transaccionId' => $transaction, 'reintento' => true], 200, $config);
                return;
            }
            inout_error('Identificador de carga ya utilizado.', $config, 409);
        }
        throw $e;
    }
    $push = ['sent' => 0, 'ok' => true];
    if ($usuarioVisita) {
        try { $push = inout_send_notification($pdo, $transaction); }
        catch (Throwable $e) { error_log('[inout] push pendiente: ' . $e->getMessage()); $push['ok'] = false; }
    }
    json_response(['ok' => true, 'cantidad' => count($personas), 'transaccionId' => $transaction, 'notificacionPendiente' => !$push['ok']], 201, $config);
}
