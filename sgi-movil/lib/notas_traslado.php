<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const NT_STATUS_GENERADA = 1;
const NT_STATUS_RECEPCION_DESPACHO = 2;
const NT_STATUS_EN_TRANSITO = 3;
const NT_STATUS_RECEPCION_SEDE = 4;
const NT_STATUS_ENTREGADO = 5;
const NT_STATUS_RECIBIDA = 6;
const NT_STATUS_ANULADA = 7;

function notas_traslado_has_role(array $roles, string $roleName): bool
{
    if (function_exists('user_has_role_name')) {
        return user_has_role_name($roles, $roleName);
    }

    $expected = strtoupper(trim($roleName));
    foreach ($roles as $role) {
        if (strtoupper(trim((string) $role)) === $expected) {
            return true;
        }
    }
    return false;
}

function notas_traslado_require_role(array $roles, array $config): void
{
    require_any_role($roles, $config);
    $allowed = [
        'SYSADMIN',
        'ADMIN',
        'NOTA_TRASLADO',
        'NOTA_TRASLADO_FULL_USERS',
        'NOTA_TRASLADO_DESPACHO',
        'NOTA_TRASLADO_TRANSPORTE',
    ];
    $upper = array_map(static fn ($r) => strtoupper(trim((string) $r)), $roles);
    if (array_intersect($allowed, $upper) === []) {
        json_response(['message' => 'Sin permisos para Notas de Traslado'], 403, $config);
        exit;
    }
}

function notas_traslado_is_admin(array $roles): bool
{
    return notas_traslado_has_role($roles, 'SYSADMIN') || notas_traslado_has_role($roles, 'ADMIN');
}

function notas_traslado_extract_id(mixed $raw): string
{
    $value = trim((string) $raw);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
        return strtolower($value);
    }

    $query = parse_url($value, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        parse_str($query, $params);
        foreach (['key', 'id', 'notaTrasladoId'] as $param) {
            if (isset($params[$param]) && is_string($params[$param])) {
                return notas_traslado_extract_id($params[$param]);
            }
        }
    }

    if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value, $m)) {
        return strtolower($m[0]);
    }

    return '';
}

function notas_traslado_status_label(int $status): string
{
    return match ($status) {
        NT_STATUS_GENERADA => 'Generada',
        NT_STATUS_RECEPCION_DESPACHO => 'Recepcion Despacho',
        NT_STATUS_EN_TRANSITO => 'En transito',
        NT_STATUS_RECEPCION_SEDE => 'Recepcion Sede',
        NT_STATUS_ENTREGADO => 'Entregado',
        NT_STATUS_RECIBIDA => 'Recibida',
        NT_STATUS_ANULADA => 'Anulada',
        default => 'Desconocido',
    };
}

function notas_traslado_map_row(array $row): array
{
    $statusId = (int) $row['StatusNotaTrasladoId'];
    return [
        'id' => (string) $row['Id'],
        'numeroNota' => (string) $row['NumeroNota'],
        'fecha' => substr((string) $row['Fecha'], 0, 10),
        'origen' => (string) ($row['Origen'] ?? '-'),
        'destino' => (string) ($row['Destino'] ?? '-'),
        'externo' => (bool) $row['Externo'],
        'nombreEnvia' => (string) $row['NombreEnvia'],
        'nombreRecibe' => (string) $row['NombreRecibe'],
        'statusId' => $statusId,
        'status' => notas_traslado_status_label($statusId),
        'transportistaAsignadoId' => $row['TransportistaAsignadoId'] !== null ? (string) $row['TransportistaAsignadoId'] : null,
        'fechaAsignacionTransporte' => $row['FechaAsignacionTransporte'] !== null ? (string) $row['FechaAsignacionTransporte'] : null,
    ];
}

function notas_traslado_registrar_traza(PDO $pdo, string $notaId, string $accion, ?int $statusId, string $detalle, string $usuarioId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO NotasTrasladoTraza (NotaTrasladoId, FechaHora, UsuarioId, StatusNotaTrasladoId, Accion, Detalle)
         VALUES (:notaId, NOW(), :usuarioId, :statusId, :accion, :detalle)'
    );
    $stmt->execute([
        'notaId' => $notaId,
        'usuarioId' => $usuarioId,
        'statusId' => $statusId,
        'accion' => $accion,
        'detalle' => $detalle,
    ]);
}

function notas_traslado_base_select(): string
{
    return 'SELECT n.Id, n.NumeroNota, n.Fecha, n.OrigenId, n.DestinoId, n.Externo, n.NombreEnvia, n.NombreRecibe, n.UserRecibeId,
                   n.StatusNotaTrasladoId, n.TransportistaAsignadoId, n.FechaAsignacionTransporte,
                   o.Nombre AS Origen,
                   CASE WHEN n.Externo = 1 THEN COALESCE(e.Nombre, n.ReceptorExterno, "Externo")
                        ELSE COALESCE(d.Nombre, "-") END AS Destino
            FROM NotasTraslado n
            INNER JOIN Usuarios ua ON ua.Id = n.UserAdd
            LEFT JOIN Depositos o ON o.Id = n.OrigenId
            LEFT JOIN Depositos d ON d.Id = n.DestinoId
            LEFT JOIN Empresa e ON e.Id = n.EmpresaId';
}

function handle_notas_traslado_list(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    notas_traslado_require_role($auth['roles'], $config);

    $usuarioId = (string) $auth['user']['Id'];
    $depositoId = (string) ($auth['user']['DepositoId'] ?? '');
    $roles = $auth['roles'];
    $isAdmin = notas_traslado_is_admin($roles) || notas_traslado_has_role($roles, 'NOTA_TRASLADO_FULL_USERS');
    $isDespacho = notas_traslado_has_role($roles, 'NOTA_TRASLADO_DESPACHO');
    $isTransporte = notas_traslado_has_role($roles, 'NOTA_TRASLADO_TRANSPORTE');

    $where = ['n.Estado = 1'];
    $params = [];

    if (!$isAdmin) {
        $visibility = ['n.UserAdd = :usuarioId'];
        $params['usuarioId'] = $usuarioId;

        if ($isTransporte) {
            $visibility[] = 'n.TransportistaAsignadoId = :transportistaId';
            $params['transportistaId'] = $usuarioId;
        }

        if ($isDespacho && $depositoId !== '') {
            $visibility[] = '(ua.DepositoId = :depositoId AND n.StatusNotaTrasladoId IN (' . NT_STATUS_GENERADA . ',' . NT_STATUS_ENTREGADO . '))';
            $visibility[] = '(n.DestinoId = :depositoId AND n.StatusNotaTrasladoId = ' . NT_STATUS_EN_TRANSITO . ')';
            $params['depositoId'] = $depositoId;
        }

        $where[] = '(' . implode(' OR ', $visibility) . ')';
    }

    $sql = notas_traslado_base_select() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY n.DateAdd DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    json_response(['notas' => array_map('notas_traslado_map_row', $rows)], 200, $config);
}

function handle_notas_traslado_scan(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    notas_traslado_require_role($auth['roles'], $config);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $notaId = notas_traslado_extract_id($body['notaTrasladoId'] ?? $body['id'] ?? $body['key'] ?? $body['qr'] ?? '');
    if ($notaId === '') {
        json_response(['ok' => false, 'message' => 'Nota de Traslado no válida'], 400, $config);
        return;
    }

    $usuarioId = (string) $auth['user']['Id'];
    $depositoId = (string) ($auth['user']['DepositoId'] ?? '');
    $roles = $auth['roles'];
    $isTransporte = notas_traslado_has_role($roles, 'NOTA_TRASLADO_TRANSPORTE');
    $isDespacho = notas_traslado_has_role($roles, 'NOTA_TRASLADO_DESPACHO') || notas_traslado_is_admin($roles);
    $personalId = notas_traslado_personal_id_usuario($pdo, $auth['user']);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(notas_traslado_base_select() . ' WHERE n.Id = :id AND n.Estado = 1 LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $notaId]);
        $nota = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$nota) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => 'Nota de Traslado no encontrada'], 404, $config);
            return;
        }

        $status = (int) $nota['StatusNotaTrasladoId'];
        if ($isTransporte && ($status === NT_STATUS_RECEPCION_DESPACHO || $status === NT_STATUS_EN_TRANSITO)) {
            $upd = $pdo->prepare(
                'UPDATE NotasTraslado
                 SET StatusNotaTrasladoId = :status,
                     TransportistaAsignadoId = :usuarioId,
                     FechaAsignacionTransporte = NOW(),
                     UserMod = :usuarioId,
                     DateMod = NOW()
                 WHERE Id = :id AND Estado = 1'
            );
            $upd->execute([
                'status' => NT_STATUS_EN_TRANSITO,
                'usuarioId' => $usuarioId,
                'id' => $notaId,
            ]);
            notas_traslado_registrar_traza($pdo, $notaId, 'ASIGNACION_TRANSPORTE', NT_STATUS_EN_TRANSITO, 'Traslado asignado al transportista por escaneo QR.', $usuarioId);
            $pdo->commit();
            json_response(['ok' => true, 'accion' => 'asignacion_transporte', 'message' => 'Traslado asignado correctamente.'], 200, $config);
            return;
        }

        if ($isDespacho && !$nota['Externo'] && $status === NT_STATUS_EN_TRANSITO && $depositoId !== '' && strtolower((string) $nota['DestinoId']) === strtolower($depositoId)) {
            $upd = $pdo->prepare(
                'UPDATE NotasTraslado
                 SET StatusNotaTrasladoId = :status,
                     UserMod = :usuarioId,
                     DateMod = NOW()
                 WHERE Id = :id AND Estado = 1'
            );
            $upd->execute([
                'status' => NT_STATUS_RECEPCION_SEDE,
                'usuarioId' => $usuarioId,
                'id' => $notaId,
            ]);
            notas_traslado_registrar_traza($pdo, $notaId, 'RECEPCION_SEDE', NT_STATUS_RECEPCION_SEDE, 'Recepcion en sede registrada por escaneo QR.', $usuarioId);
            $pdo->commit();
            json_response(['ok' => true, 'accion' => 'recepcion_sede', 'message' => 'Recepción en sede registrada correctamente.'], 200, $config);
            return;
        }

        if (!$nota['Externo'] && $status === NT_STATUS_RECEPCION_SEDE && $personalId !== '' && strtolower((string) $nota['UserRecibeId']) === strtolower($personalId)) {
            $upd = $pdo->prepare(
                'UPDATE NotasTraslado
                 SET StatusNotaTrasladoId = :status,
                     UserMod = :usuarioId,
                     DateMod = NOW()
                 WHERE Id = :id AND Estado = 1'
            );
            $upd->execute([
                'status' => NT_STATUS_RECIBIDA,
                'usuarioId' => $usuarioId,
                'id' => $notaId,
            ]);
            notas_traslado_registrar_traza($pdo, $notaId, 'RECEPCION_FINAL_QR', NT_STATUS_RECIBIDA, 'Recepcion final registrada por escaneo QR.', $usuarioId);
            $pdo->commit();
            json_response(['ok' => true, 'accion' => 'recepcion_final', 'message' => 'Traslado recibido correctamente.'], 200, $config);
            return;
        }

        $pdo->rollBack();
        json_response(['ok' => false, 'message' => 'La nota no admite esta acción con su rol o estado actual.'], 409, $config);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function notas_traslado_personal_id_usuario(PDO $pdo, array $user): string
{
    $mail = trim((string) ($user['Mail'] ?? ''));
    $nombreCompleto = trim((string) ($user['Nombre'] ?? '') . ' ' . (string) ($user['Apellido'] ?? ''));
    if ($mail === '' && $nombreCompleto === '') {
        return '';
    }

    $stmt = $pdo->prepare(
        'SELECT Id
         FROM Personal
         WHERE Estado = 1
           AND (
                Email = :mail
                OR EmailCorporativo = :mail
                OR CONCAT(Nombre, " ", Apellido) = :nombre
                OR CONCAT(Apellido, " ", Nombre) = :nombre
           )
         LIMIT 1'
    );
    $stmt->execute([
        'mail' => $mail,
        'nombre' => $nombreCompleto,
    ]);
    $id = $stmt->fetchColumn();
    return is_string($id) ? $id : '';
}

function handle_notas_traslado_entregado(PDO $pdo, array $config, string $notaId): void
{
    $auth = require_auth($pdo, $config);
    notas_traslado_require_role($auth['roles'], $config);

    if (!notas_traslado_has_role($auth['roles'], 'NOTA_TRASLADO_TRANSPORTE') && !notas_traslado_is_admin($auth['roles'])) {
        json_response(['message' => 'Sin permisos para entregar traslados'], 403, $config);
        return;
    }

    $notaId = notas_traslado_extract_id($notaId);
    $usuarioId = (string) $auth['user']['Id'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT Id, Externo, StatusNotaTrasladoId, TransportistaAsignadoId FROM NotasTraslado WHERE Id = :id AND Estado = 1 LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $notaId]);
        $nota = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$nota) {
            $pdo->rollBack();
            json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
            return;
        }

        if (!(bool) $nota['Externo'] || (int) $nota['StatusNotaTrasladoId'] !== NT_STATUS_EN_TRANSITO || (string) $nota['TransportistaAsignadoId'] !== $usuarioId) {
            $pdo->rollBack();
            json_response(['message' => 'El traslado no puede marcarse como entregado.'], 409, $config);
            return;
        }

        $upd = $pdo->prepare(
            'UPDATE NotasTraslado
             SET StatusNotaTrasladoId = :status,
                 UserMod = :usuarioId,
                 DateMod = NOW()
             WHERE Id = :id AND Estado = 1'
        );
        $upd->execute([
            'status' => NT_STATUS_ENTREGADO,
            'usuarioId' => $usuarioId,
            'id' => $notaId,
        ]);
        notas_traslado_registrar_traza($pdo, $notaId, 'ENTREGA_EXTERNA', NT_STATUS_ENTREGADO, 'Traslado externo marcado como entregado por transporte.', $usuarioId);
        $pdo->commit();
        json_response(['ok' => true, 'message' => 'Entrega registrada correctamente.'], 200, $config);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function handle_notas_traslado_routes(PDO $pdo, array $config, string $path, string $method): bool
{
    if ($method === 'GET' && $path === '/notas-traslado') {
        handle_notas_traslado_list($pdo, $config);
        return true;
    }

    if ($method === 'POST' && $path === '/notas-traslado/scan') {
        handle_notas_traslado_scan($pdo, $config);
        return true;
    }

    if ($method === 'POST' && preg_match('#^/notas-traslado/([^/]+)/entregado$#', $path, $m)) {
        handle_notas_traslado_entregado($pdo, $config, $m[1]);
        return true;
    }

    return false;
}
