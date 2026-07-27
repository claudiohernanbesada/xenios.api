<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const AUTH_SALIDA_SCAN_ROLE = 'AUTH_SALIDA_SCAN_QR';
const AUTH_SALIDA_MIN_REGRESO_MINUTOS = 5;

function user_has_role_name(array $roles, string $roleName): bool
{
    $expected = strtoupper(trim($roleName));
    foreach ($roles as $role) {
        if (strtoupper(trim((string) $role)) === $expected) {
            return true;
        }
    }
    return false;
}

function require_auth_salida_scan_role(array $roles, array $config): void
{
    require_any_role($roles, $config);
    if (!user_has_role_name($roles, AUTH_SALIDA_SCAN_ROLE)) {
        json_response(['message' => 'Sin permisos para escanear autorizaciones de salida'], 403, $config);
        exit;
    }
}

function auth_salida_scan_client_ip(): string
{
    $candidatos = [
        $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
        $_SERVER['HTTP_CLIENT_IP'] ?? '',
        $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    foreach ($candidatos as $raw) {
        $ip = trim(explode(',', (string) $raw)[0]);
        if ($ip !== '') {
            return $ip;
        }
    }

    return '';
}

function auth_salida_scan_extract_id(mixed $raw): string
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
        if (isset($params['key']) && is_string($params['key'])) {
            return auth_salida_scan_extract_id($params['key']);
        }
    }

    if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value, $m)) {
        return strtolower($m[0]);
    }

    return '';
}

function auth_salida_scan_invalid(array $config): void
{
    json_response(['ok' => false, 'message' => 'Autorización de Salida no válida/vigente'], 400, $config);
}

function handle_auth_salida_scan(PDO $pdo, array $config): void
{
    $auth = require_auth($pdo, $config);
    require_auth_salida_scan_role($auth['roles'], $config);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $authSalidaId = auth_salida_scan_extract_id($body['authSalidaId'] ?? $body['key'] ?? $body['qr'] ?? '');
    if ($authSalidaId === '') {
        auth_salida_scan_invalid($config);
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT Id, Fecha, Estado, FechaHoraSalidaEfectiva, FechaHoraRegresoEfectiva
         FROM AuthSalida
         WHERE Id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $authSalidaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $tz = new DateTimeZone('America/Argentina/Buenos_Aires');
    $now = new DateTimeImmutable('now', $tz);
    $today = $now->format('Y-m-d');
    if (!$row || (int) $row['Estado'] !== 1 || substr((string) $row['Fecha'], 0, 10) !== $today) {
        auth_salida_scan_invalid($config);
        return;
    }

    if (!empty($row['FechaHoraSalidaEfectiva']) && !empty($row['FechaHoraRegresoEfectiva'])) {
        json_response(['ok' => false, 'message' => 'La autorización de salida ya registra salida y regreso efectivos'], 409, $config);
        return;
    }

    $usuarioId = (string) ($auth['user']['Id'] ?? '');
    $ip = auth_salida_scan_client_ip();
    $nowSql = $now->format('Y-m-d H:i:s');

    if (empty($row['FechaHoraSalidaEfectiva'])) {
        $upd = $pdo->prepare(
            'UPDATE AuthSalida
             SET FechaHoraSalidaEfectiva = :fecha,
                 IpSalidaEfectiva = :ip,
                 DateAuthScanQrSalida = :fecha,
                 UserAuthScanQrSalida = :usuario,
                 DateUpdate = :fecha,
                 UserUpdate = :usuario
             WHERE Id = :id
               AND Estado = 1
               AND FechaHoraSalidaEfectiva IS NULL'
        );
        $upd->execute([
            'fecha' => $nowSql,
            'ip' => $ip,
            'usuario' => $usuarioId,
            'id' => $authSalidaId,
        ]);

        if ($upd->rowCount() < 1) {
            auth_salida_scan_invalid($config);
            return;
        }

        json_response([
            'ok' => true,
            'accion' => 'salida',
            'message' => 'Se registró la Salida con éxito!',
            'fechaHora' => $nowSql,
        ], 200, $config);
        return;
    }

    $salida = new DateTimeImmutable((string) $row['FechaHoraSalidaEfectiva']);
    $minutosDesdeSalida = ($now->getTimestamp() - $salida->getTimestamp()) / 60.0;
    if ($minutosDesdeSalida < AUTH_SALIDA_MIN_REGRESO_MINUTOS) {
        json_response([
            'ok' => false,
            'message' => 'No es posible registrar la Hora de Regreso Efectiva ya que no se ha superado el tiempo mínimo para hacerlo. Vuelva a intentarlo más tarde',
        ], 409, $config);
        return;
    }

    $upd = $pdo->prepare(
        'UPDATE AuthSalida
         SET FechaHoraRegresoEfectiva = :fecha,
             IpRegresoEfectiva = :ip,
             DateAuthScanQrSRegreso = :fecha,
             UserAuthScanQrRegreso = :usuario,
             DateUpdate = :fecha,
             UserUpdate = :usuario
         WHERE Id = :id
           AND Estado = 1
           AND FechaHoraSalidaEfectiva IS NOT NULL
           AND FechaHoraRegresoEfectiva IS NULL'
    );
    $upd->execute([
        'fecha' => $nowSql,
        'ip' => $ip,
        'usuario' => $usuarioId,
        'id' => $authSalidaId,
    ]);

    if ($upd->rowCount() < 1) {
        auth_salida_scan_invalid($config);
        return;
    }

    json_response([
        'ok' => true,
        'accion' => 'regreso',
        'message' => 'Se registró el Regreso con éxito!',
        'fechaHora' => $nowSql,
    ], 200, $config);
}
