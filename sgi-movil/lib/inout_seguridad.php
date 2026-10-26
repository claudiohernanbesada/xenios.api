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

function inout_horario_futuro(string $horario, ?DateTimeImmutable $ahora = null): bool
{
    $ahora = ($ahora ?? new DateTimeImmutable('now'))->setTimezone(novedades_timezone());
    return $horario > $ahora->format('H:i');
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
    usort($rows, static fn(array $a, array $b): int => strcmp($a['Apellido'], $b['Apellido']) ?: strcmp($a['Nombre'], $b['Nombre']));
    $ingreso = $rows[0]['Movimiento'] === 'Ingreso';
    $tipoPersona = $rows[0]['TipoPersona'] ?? 'Invitado';
    $empresa = implode(', ', array_values(array_unique(array_column($rows, 'EmpresaNombre'))));
    // Horario es la hora del movimiento, que puede diferir de la hora de guardado.
    $fechaHora = (new DateTimeImmutable(substr($rows[0]['DateAdd'], 0, 10) . ' ' . $rows[0]['Horario']))->format('d/m/Y H:i') . ' hs.';
    $nota = implode("\n\n", array_values(array_unique(array_filter(array_column($rows, 'Nota'), static fn($value): bool => $value !== null && $value !== ''))));
    return [
        'transaccionId' => $rows[0]['TransaccionId'],
        'title' => ($ingreso ? 'Ingreso de ' : 'Salida de ') . ($tipoPersona === 'Externo' ? 'Personal Externo' : 'Visita'),
        'movimiento' => $rows[0]['Movimiento'],
        'tipoPersona' => $tipoPersona,
        'empresa' => $empresa,
        'fechaHora' => $fechaHora,
        'nota' => $nota,
        'body' => 'Fecha y Hora: ' . $fechaHora . "\nEmpresa: " . $empresa . "\n\n"
            . ($ingreso ? 'Esta ingresando a la empresa:' : 'Esta saliendo de la empresa:') . "\n"
            . implode("\n", array_map(static fn(array $r): string => '✓ ' . $r['Nombre'] . ' ' . $r['Apellido'], $rows))
            . ($nota !== '' ? "\n\nNota: " . $nota : ''),
        'personas' => array_map(static fn(array $r): array => ['nombre' => $r['Nombre'], 'apellido' => $r['Apellido']], $rows),
    ];
}

function inout_build_push_messages(array $tokens, array $notification): array
{
    $pushBody = inout_text_cut($notification['body'], 2200);
    if ($pushBody !== $notification['body']) $pushBody .= '… Ver listado completo en la app.';
    $messages = [];
    foreach ($tokens as $token) {
        if (!push_native_token_valido($token['token'], $token['plataforma'])) continue;
        $messages[] = $token + [
            'title' => $notification['title'], 'body' => $pushBody,
            'channelId' => 'visitas-v1', 'sound' => 'campana.wav',
            'notificationPriority' => 'PRIORITY_MAX',
            'vibrationPattern' => [0, 500, 300, 500, 300, 700],
            'data' => ['tipo' => 'inoutSeguridad', 'transaccionId' => $notification['transaccionId']],
        ];
    }
    return $messages;
}

// La notificación queda persistida junto a los movimientos; el destinatario puede
// recuperarla incluso si el proveedor o su dispositivo estaban desconectados.
function inout_guardar_destinatarios(PDO $pdo, string $registroId, array $usuarios): void
{
    if ($usuarios === []) return;
    $values = []; $params = [];
    foreach (array_unique($usuarios) as $uid) { $values[] = '(?, ?)'; array_push($params, $registroId, $uid); }
    $pdo->prepare('INSERT INTO InOutSeguridadDestinatario (InOutSeguridadId, UsuarioId) VALUES ' . implode(',', $values))->execute($params);
}

function inout_destinatarios_registro(PDO $pdo, array $registro): array
{
    $q = $pdo->prepare('SELECT UsuarioId FROM InOutSeguridadDestinatario WHERE InOutSeguridadId = ? ORDER BY UsuarioId');
    $q->execute([$registro['Id']]);
    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
    return $ids ?: (!empty($registro['UsuarioVisitaId']) ? [$registro['UsuarioVisitaId']] : []);
}

/** Incluye filas antiguas escritas por una API anterior durante el despliegue. */
function inout_notificaciones_usuario(PDO $pdo, string $uid, ?string $transaccion = null): array
{
    $extra = $transaccion === null ? '' : ' AND i.TransaccionId = ?';
    $params = $transaccion === null ? [$uid] : [$uid, $transaccion];
    $q = $pdo->prepare('SELECT i.* FROM InOutSeguridad i
        INNER JOIN InOutSeguridadDestinatario d ON d.InOutSeguridadId = i.Id
        WHERE d.UsuarioId = ? AND d.NotificacionLeidaEn IS NULL AND i.Estado = 1' . $extra . '
        UNION ALL SELECT i.* FROM InOutSeguridad i
        WHERE i.UsuarioVisitaId = ? AND i.NotificacionLeidaEn IS NULL AND i.Estado = 1
        AND NOT EXISTS (SELECT 1 FROM InOutSeguridadDestinatario d WHERE d.InOutSeguridadId = i.Id)' . $extra . '
        ORDER BY DateAdd, Apellido, Nombre, Id');
    $q->execute(array_merge($params, $params));
    return $q->fetchAll();
}

function inout_destinatarios_pendientes(PDO $pdo, ?string $desde = null, int $segundos = 60): array
{
    $segundos = max(0, min(86400, $segundos));
    $date = $desde === null ? '' : ' AND i.DateAdd >= ?';
    $q = $pdo->prepare("SELECT DISTINCT i.TransaccionId, d.UsuarioId AS UsuarioVisitaId FROM InOutSeguridad i
        INNER JOIN InOutSeguridadDestinatario d ON d.InOutSeguridadId = i.Id
        WHERE i.Estado = 1 AND d.NotificacionLeidaEn IS NULL
        AND i.DateAdd <= DATE_SUB(NOW(), INTERVAL $segundos SECOND)
        AND (d.NotificacionEnviadaEn IS NULL OR d.NotificacionEnviadaEn <= DATE_SUB(NOW(), INTERVAL $segundos SECOND))" . $date . "
        UNION SELECT DISTINCT i.TransaccionId, i.UsuarioVisitaId FROM InOutSeguridad i
        WHERE i.Estado = 1 AND i.UsuarioVisitaId IS NOT NULL AND i.NotificacionLeidaEn IS NULL
        AND NOT EXISTS (SELECT 1 FROM InOutSeguridadDestinatario d WHERE d.InOutSeguridadId = i.Id)
        AND i.DateAdd <= DATE_SUB(NOW(), INTERVAL $segundos SECOND)
        AND (i.NotificacionEnviadaEn IS NULL OR i.NotificacionEnviadaEn <= DATE_SUB(NOW(), INTERVAL $segundos SECOND))" . $date);
    $q->execute($desde === null ? [] : [$desde, $desde]);
    return $q->fetchAll();
}

function inout_send_notification(PDO $pdo, string $transaccionId, ?string $destinatario = null): array
{
    if ($destinatario === null) {
        $stmt = $pdo->prepare('SELECT DISTINCT d.UsuarioId FROM InOutSeguridad i
            INNER JOIN InOutSeguridadDestinatario d ON d.InOutSeguridadId = i.Id
            WHERE i.TransaccionId = ? AND i.Estado = 1 AND d.NotificacionLeidaEn IS NULL
            UNION SELECT DISTINCT i.UsuarioVisitaId FROM InOutSeguridad i WHERE i.TransaccionId = ?
            AND i.Estado = 1 AND i.UsuarioVisitaId IS NOT NULL AND i.NotificacionLeidaEn IS NULL
            AND NOT EXISTS (SELECT 1 FROM InOutSeguridadDestinatario d WHERE d.InOutSeguridadId = i.Id)');
        $stmt->execute([$transaccionId, $transaccionId]);
        $result = ['sent' => 0, 'ok' => true];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $sent = inout_send_notification($pdo, $transaccionId, $uid);
            $result['sent'] += $sent['sent'];
            $result['ok'] = $result['ok'] && $sent['ok'];
        }
        return $result;
    }
    $rows = inout_notificaciones_usuario($pdo, $destinatario, $transaccionId);
    if ($rows === []) return ['sent' => 0, 'ok' => true];
    $notification = inout_notification($rows);
    $tokens = novedades_tokens_por_usuarios($pdo, [$destinatario]);
    $messages = inout_build_push_messages($tokens, $notification);
    $result = push_native_send_batch($messages);
    if ($messages === [] || !$result['ok']) {
        $reason = $messages === [] ? 'El destinatario no tiene tokens nativos registrados.' : implode(' | ', $result['errors']);
        error_log('[inout-push] transaccion=' . $transaccionId . ' destinatario=' . $destinatario . ' ' . inout_text_cut($reason, 1200));
    }
    if ($result['sent'] > 0) {
        $pdo->prepare('UPDATE InOutSeguridadDestinatario d INNER JOIN InOutSeguridad i ON i.Id = d.InOutSeguridadId
            SET d.NotificacionEnviadaEn = NOW() WHERE i.TransaccionId = ? AND d.UsuarioId = ?')->execute([$transaccionId, $destinatario]);
        $pdo->prepare('UPDATE InOutSeguridad SET NotificacionEnviadaEn = NOW() WHERE TransaccionId = ? AND UsuarioVisitaId = ?')->execute([$transaccionId, $destinatario]);
    }
    return ['sent' => $result['sent'], 'ok' => $result['ok'] && $messages !== []];
}

function inout_presentes(PDO $pdo, string $sede, string $tipo, string $query = '', ?array $ids = null): array
{
    // Los egresos nuevos cierran un ingreso concreto. Para movimientos anteriores
    // sin vínculo, se respeta el último movimiento de ese DNI en la misma sede.
    $sql = "SELECT i.*, m.Nombre AS MotivoNombre FROM InOutSeguridad i
        INNER JOIN InOutSeguridadMotivo m ON m.Id = i.MotivoId
        WHERE i.Sede = ? AND i.TipoPersona = ? AND i.Estado = 1 AND i.Movimiento = 'Ingreso'
        AND NOT EXISTS (SELECT 1 FROM InOutSeguridad e WHERE e.IngresoId = i.Id)
        AND NOT EXISTS (SELECT 1 FROM InOutSeguridad n WHERE n.Sede = i.Sede AND n.Dni = i.Dni
            AND n.Estado = 1 AND n.IngresoId IS NULL AND n.Id <> i.Id
            AND (n.DateAdd > i.DateAdd OR (n.DateAdd = i.DateAdd AND
                (n.Horario > i.Horario OR (n.Horario = i.Horario AND
                    (n.Movimiento = 'Egreso' OR n.Id > i.Id))))))";
    $params = [$sede, $tipo];
    if ($ids !== null) {
        $sql .= ' AND i.Id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $params = array_merge($params, $ids);
    } else {
        $sql .= " AND CONCAT(i.Nombre, ' ', i.Apellido, ' ', i.Dni, ' ', i.EmpresaNombre) LIKE ?";
        $params[] = '%' . trim($query) . '%';
    }
    $sql .= ' ORDER BY i.Apellido, i.Nombre, i.Id LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function inout_egreso_desde_ingresos(PDO $pdo, array $config, array $b, string $sede, string $uid): void
{
    $ids = $b['ingresosIds'];
    if (!is_array($ids) || count($ids) < 1 || count($ids) > 50) inout_error('Seleccioná entre 1 y 50 personas presentes.', $config);
    foreach ($ids as $id) {
        if (!is_string($id) || !preg_match('/^[0-9a-f-]{36}$/i', $id)) inout_error('Ingreso seleccionado inválido.', $config);
    }
    if (count(array_unique($ids)) !== count($ids)) inout_error('Hay personas repetidas en la selección.', $config);
    sort($ids); // Mismo orden de bloqueo para cargas concurrentes con varias personas.
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT Id FROM InOutSeguridad WHERE Id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY Id FOR UPDATE');
        $lock->execute($ids);
        $lock->fetchAll();
        $retry = $pdo->prepare('SELECT UserAdd, COUNT(*) AS Cantidad FROM InOutSeguridad WHERE TransaccionId = ? GROUP BY UserAdd');
        $retry->execute([$b['transaccionId']]);
        if ($existing = $retry->fetch()) {
            $pdo->rollBack();
            if ($existing['UserAdd'] !== $uid) inout_error('Identificador ya utilizado.', $config, 409);
            json_response(['ok' => true, 'cantidad' => (int) $existing['Cantidad'], 'reintento' => true], 200, $config);
            return;
        }
        $rows = inout_presentes($pdo, $sede, $b['tipoPersona'], '', $ids);
        if (count($rows) !== count($ids)) {
            $pdo->rollBack();
            inout_error('Alguna persona ya se retiró o no pertenece a esta sede y tipo. Actualizá la selección.', $config, 409);
        }
        $insert = $pdo->prepare("INSERT INTO InOutSeguridad (Id, TransaccionId, IngresoId, TipoPersona, Movimiento, Nombre, Apellido, Dni, EmpresaId, EmpresaNombre, Horario, MotivoId, UsuarioVisitaId, Nota, Sede, DateAdd, UserAdd, Estado)
            VALUES (?, ?, ?, ?, 'Egreso', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 1)");
        foreach ($rows as $row) {
            $egresoId = inout_uuid();
            $insert->execute([$egresoId, $b['transaccionId'], $row['Id'], $row['TipoPersona'], $row['Nombre'], $row['Apellido'], $row['Dni'],
                $row['EmpresaId'], $row['EmpresaNombre'], $b['horario'] . ':00', $row['MotivoId'], $row['UsuarioVisitaId'],
                trim((string) ($b['nota'] ?? '')) ?: null, $sede, $uid]);
            inout_guardar_destinatarios($pdo, $egresoId, inout_destinatarios_registro($pdo, $row));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $push = ['ok' => true];
    try { $push = inout_send_notification($pdo, $b['transaccionId']); }
    catch (Throwable $e) { error_log('[inout] push pendiente: ' . $e->getMessage()); $push['ok'] = false; }
    json_response(['ok' => true, 'cantidad' => count($rows), 'transaccionId' => $b['transaccionId'], 'notificacionPendiente' => !$push['ok']], 201, $config);
}

function handle_inout_seguridad(PDO $pdo, array $config, string $path, string $method): void
{
    $historial = preg_match('#^/inout-seguridad/historial/([0-9a-f-]{36})$#i', $path, $historialMatch) === 1;
    $notificacion = $historial || in_array($path, ['/inout-seguridad/notificaciones', '/inout-seguridad/leida'], true);
    $auth = $notificacion ? require_auth_or_web_bridge($pdo, $config) : require_auth($pdo, $config);
    $uid = $auth['user']['Id'];
    if ($historial && $method === 'GET') {
        require_once __DIR__ . '/novedades_historial.php';
        $items = novedades_historial_visitas($pdo, $uid, null, null, $historialMatch[1]);
        if ($items === []) { json_response(['message' => 'Aviso de visita no encontrado.'], 404, $config); return; }
        json_response(['novedad' => $items[0]], 200, $config);
        return;
    }

    // Las visitas están dirigidas al usuario: no requieren permiso de portería.
    if ($path === '/inout-seguridad/notificaciones' && $method === 'GET') {
        $groups = [];
        foreach (inout_notificaciones_usuario($pdo, $uid) as $row) $groups[$row['TransaccionId']][] = $row;
        $result = ['notificaciones' => array_values(array_map('inout_notification', $groups))];
        // El puente web comparte la cola y la lectura personal de Novedades.
        // Los clientes móviles conservan el contrato de visitas existente.
        if (($auth['token_payload']['source'] ?? '') === 'web') {
            $news = $pdo->prepare('SELECT n.Id, n.Titulo, n.Cuerpo, n.CreadoEn,
                n.EnviadoPorUsuarioId, nu.LeidaEn, u.Nombre AS EnvNombre,
                u.Apellido AS EnvApellido, u.Mail AS EnvMail
                FROM MovilNovedadUsuario nu INNER JOIN MovilNovedad n ON n.Id = nu.NovedadId
                LEFT JOIN Usuarios u ON u.Id = n.EnviadoPorUsuarioId
                WHERE nu.UsuarioId = ? AND nu.LeidaEn IS NULL ORDER BY n.CreadoEn, n.Id LIMIT 100');
            $news->execute([$uid]);
            $result['novedades'] = array_map('novedades_map_item', $news->fetchAll());
        }
        json_response($result, 200, $config);
        return;
    }
    if ($path === '/inout-seguridad/leida' && $method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = (string) ($body['transaccionId'] ?? '');
        if (($auth['token_payload']['source'] ?? '') === 'web') {
            // El controlador web transporta un GUID. Ambos tipos tienen UUID
            // independientes; nunca se confirma una novedad de otro destinatario.
            $news = $pdo->prepare('SELECT 1 FROM MovilNovedadUsuario WHERE NovedadId = ? AND UsuarioId = ?');
            $news->execute([$id, $uid]);
            if ($news->fetchColumn()) {
                json_response(['ok' => novedades_marcar_leida($pdo, $uid, $id)], 200, $config);
                return;
            }
        }
        $pdo->prepare('UPDATE InOutSeguridadDestinatario d INNER JOIN InOutSeguridad i ON i.Id = d.InOutSeguridadId
            SET d.NotificacionLeidaEn = COALESCE(d.NotificacionLeidaEn, NOW()) WHERE i.TransaccionId = ? AND d.UsuarioId = ? AND i.Estado = 1')->execute([$id, $uid]);
        $pdo->prepare('UPDATE InOutSeguridad SET NotificacionLeidaEn = COALESCE(NotificacionLeidaEn, NOW()) WHERE TransaccionId = ? AND UsuarioVisitaId = ? AND Estado = 1')
            ->execute([(string) ($body['transaccionId'] ?? ''), $uid]);
        $own = $pdo->prepare('SELECT 1 FROM InOutSeguridad i LEFT JOIN InOutSeguridadDestinatario d ON d.InOutSeguridadId = i.Id
            WHERE i.TransaccionId = ? AND (d.UsuarioId = ? OR i.UsuarioVisitaId = ?) AND i.Estado = 1 LIMIT 1');
        $own->execute([$id, $uid, $uid]);
        if ($own->fetchColumn()) novedades_cancelar_alerta($pdo, $uid, $id, 'visita');
        json_response(['ok' => true], 200, $config);
        return;
    }

    inout_require_permission($auth, $config);
    $sede = inout_sede($pdo, $auth['user'], $config);
    if ($path === '/inout-seguridad/presentes' && $method === 'GET') {
        $tipo = (string) ($_GET['tipoPersona'] ?? '');
        if (!in_array($tipo, ['Externo', 'Invitado'], true)) inout_error('Seleccioná externos o invitados.', $config);
        $rows = inout_presentes($pdo, $sede, $tipo, (string) ($_GET['q'] ?? ''));
        $personas = array_map(static fn(array $r): array => [
            'id' => $r['Id'], 'label' => $r['Nombre'] . ' ' . $r['Apellido'], 'dni' => $r['Dni'],
            'empresa' => $r['EmpresaNombre'], 'motivo' => $r['MotivoNombre'],
            'ingreso' => date('d/m/Y', strtotime($r['DateAdd'])) . ' ' . substr($r['Horario'], 0, 5),
        ], $rows);
        json_response(['personas' => $personas, 'sede' => $sede], 200, $config);
        return;
    }
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
        $fecha = (string) ($_GET['fecha'] ?? date('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fecha)) inout_error('Fecha inválida. Usá el formato YYYY-MM-DD.', $config);
        $dia = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
        if (!$dia || $dia->format('Y-m-d') !== $fecha) inout_error('Fecha inválida. Usá el formato YYYY-MM-DD.', $config);
        $params = [$sede, $dia->format('Y-m-d H:i:s'), $dia->modify('+1 day')->format('Y-m-d H:i:s')];
        $page = max(1, min(1000000, (int) ($_GET['pagina'] ?? 1)));
        $count = $pdo->prepare('SELECT COUNT(*) FROM InOutSeguridad WHERE Sede = ? AND Estado = 1 AND DateAdd >= ? AND DateAdd < ?');
        $count->execute($params);
        $offset = ($page - 1) * 20;
        $stmt = $pdo->prepare("SELECT Id AS id, Movimiento AS movimiento, DATE_FORMAT(Horario, '%H:%i') AS horario, Nombre AS nombre, Apellido AS apellido, EmpresaNombre AS empresa, DATE_FORMAT(DateAdd, '%d/%m/%Y') AS fecha FROM InOutSeguridad WHERE Sede = ? AND Estado = 1 AND DateAdd >= ? AND DateAdd < ? ORDER BY Horario DESC, DateAdd DESC, Id DESC LIMIT 20 OFFSET $offset");
        $stmt->execute($params);
        json_response(['registros' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn(), 'pagina' => $page, 'sede' => $sede, 'fecha' => $fecha], 200, $config);
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
    // Resolver reintentos antes de comprobar presencia: el primer envío ya pudo
    // haber cerrado los ingresos aunque el teléfono no recibiera la respuesta.
    $stmt = $pdo->prepare('SELECT UserAdd, COUNT(*) AS Cantidad FROM InOutSeguridad WHERE TransaccionId = ? GROUP BY UserAdd');
    $stmt->execute([$transaction]);
    $existing = $stmt->fetch();
    if ($existing) {
        if ($existing['UserAdd'] !== $uid) inout_error('Identificador ya utilizado.', $config, 409);
        json_response(['ok' => true, 'cantidad' => (int) $existing['Cantidad'], 'transaccionId' => $transaction, 'reintento' => true], 200, $config);
        return;
    }
    if (inout_horario_futuro($hora)) inout_error('El horario no puede ser posterior a la hora actual.', $config);
    if (array_key_exists('ingresosIds', $b)) {
        if ($movimiento !== 'Egreso' || !in_array($tipo, ['Externo', 'Invitado'], true)) inout_error('La selección de presentes corresponde a egresos de externos o invitados.', $config);
        inout_egreso_desde_ingresos($pdo, $config, $b, $sede, $uid);
        return;
    }
    $stmt = $pdo->prepare('SELECT Id FROM InOutSeguridadMotivo WHERE Id = ? AND Estado = 1');
    $stmt->execute([$b['motivoId'] ?? '']);
    if (!$stmt->fetchColumn()) inout_error('Seleccioná un motivo activo.', $config);
    $destinatarios = [];
    if ($tipo !== 'RAEI') {
        $destinatarios = $b['usuariosVisitaIds'] ?? (empty($b['usuarioVisitaId']) ? [] : [$b['usuarioVisitaId']]);
        if (!is_array($destinatarios) || count($destinatarios) > 50) inout_error('Seleccioná hasta 50 destinatarios.', $config);
        foreach ($destinatarios as &$destinatario) {
            if (!is_string($destinatario) || !preg_match('/^[0-9a-f-]{36}$/i', $destinatario)) inout_error('Destinatario inválido.', $config);
            $destinatario = strtolower($destinatario);
        }
        unset($destinatario);
        $destinatarios = array_values(array_unique($destinatarios));
    }
    $usuarioVisita = $destinatarios[0] ?? null;
    if ($tipo === 'Invitado' && !$usuarioVisita) inout_error('Para invitados es obligatorio seleccionar a quién visitan.', $config);
    if ($destinatarios !== []) {
        $stmt = $pdo->prepare('SELECT Id FROM Usuarios WHERE Estado = 1 AND Id IN (' . implode(',', array_fill(0, count($destinatarios), '?')) . ')');
        $stmt->execute($destinatarios);
        if (count($stmt->fetchAll()) !== count($destinatarios)) inout_error('Uno de los usuarios visitados no está disponible.', $config);
    }
    $empresaId = null; $empresa = 'RAEI';
    if ($tipo === 'RAEI') {
        // Conservar compatibilidad con clientes que envían un único legajo.
        $legajos = array_key_exists('legajos', $b) ? $b['legajos'] : [$b['legajo'] ?? null];
        if (!is_array($legajos) || !array_is_list($legajos) || count($legajos) < 1 || count($legajos) > 50) inout_error('Agregá entre 1 y 50 legajos.', $config);
        foreach ($legajos as &$numero) {
            if ((!is_int($numero) && !is_string($numero)) || !preg_match('/^\d{1,4}$/D', (string) $numero) || (int) $numero < 1) inout_error('Ingresá legajos válidos de hasta 4 dígitos.', $config);
            $numero = (int) $numero;
        }
        unset($numero);
        if (count(array_unique($legajos)) !== count($legajos)) inout_error('Hay legajos repetidos en la carga.', $config);
        $stmt = $pdo->prepare('SELECT Id, Legajo, Nombre AS nombre, Apellido AS apellido, Dni AS dni FROM Personal WHERE Legajo IN (' . implode(',', array_fill(0, count($legajos), '?')) . ') AND Estado = 1');
        $stmt->execute($legajos);
        $found = $stmt->fetchAll();
        if (count($found) !== count($legajos) || count(array_unique(array_column($found, 'Legajo'))) !== count($legajos)) inout_error('Uno de los legajos no está disponible o es ambiguo.', $config);
        if (count(array_unique(array_column($found, 'dni'))) !== count($found)) inout_error('Los empleados seleccionados tienen un DNI repetido. Revisá sus datos.', $config);
        $personas = $found;
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
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO InOutSeguridad (Id, TransaccionId, TipoPersona, Movimiento, PersonalId, Legajo, Nombre, Apellido, Dni, EmpresaId, EmpresaNombre, Horario, MotivoId, UsuarioVisitaId, Nota, Sede, DateAdd, UserAdd, Estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 1)');
        foreach ($personas as $p) {
            $registroId = inout_uuid();
            $stmt->execute([$registroId, $transaction, $tipo, $movimiento, $tipo === 'RAEI' ? $p['Id'] : null, $tipo === 'RAEI' ? $p['Legajo'] : null, $p['nombre'], $p['apellido'], $p['dni'], $empresaId, $empresa, $hora . ':00', $b['motivoId'], $usuarioVisita ?: null, $nota ?: null, $sede, $uid]);
            inout_guardar_destinatarios($pdo, $registroId, $destinatarios);
        }
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
