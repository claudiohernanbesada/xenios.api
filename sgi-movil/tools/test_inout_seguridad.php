<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/inout_seguridad.php';
$config = require dirname(__DIR__) . '/config.php';
$pdo = db_connect($config);
$ids = []; $checks = 0;
function check_inout(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException('Fallo: ' . $label);
    $checks++; echo 'OK ' . $label . PHP_EOL;
}
function http_inout(string $path, ?string $token, string $method = 'GET', ?array $body = null, bool $apiKey = true, ?string $webUserId = null, bool $validSignature = true): array {
    global $config;
    $curl = curl_init('http://127.0.0.1:8097/sgi-movil' . $path);
    $headers = ['Content-Type: application/json'];
    if ($apiKey) $headers[] = 'X-API-KEY: ' . config_api_key($config);
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    if ($webUserId) {
        $ts = (string) time();
        $headers[] = 'X-SGI-WEB-USER-ID: ' . $webUserId;
        $headers[] = 'X-SGI-WEB-TS: ' . $ts;
        $headers[] = 'X-SGI-WEB-SIGNATURE: ' . ($validSignature ? hash_hmac('sha256', $webUserId . '|' . $ts, config_api_key($config)) : 'invalid');
    }
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 15]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    $raw = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if ($raw === false) throw new RuntimeException(curl_error($curl));
    curl_close($curl);
    return ['status' => $status, 'body' => json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}
function fixture_user(string $nombre, string $sede, ?int $role): array {
    global $pdo, $config, $ids;
    $id = inout_uuid(); $ids[] = $id;
    $stmt = $pdo->prepare('SELECT Id FROM Depositos WHERE Nombre = ? AND Estado = 1'); $stmt->execute([$sede]);
    $deposito = $stmt->fetchColumn();
    $mail = 'inout-test-' . $id . '@example.invalid';
    $pdo->prepare('INSERT INTO Usuarios (Id, Nombre, Apellido, Mail, Passwd, Estado, dateUpdate, DepositoId, RequiereDobleFactor, DobleFactorIntentos, DobleFactorVersion) VALUES (?, ?, ?, ?, ?, 1, NOW(), ?, 0, 0, ?)')
        ->execute([$id, $nombre, 'Prueba', $mail, sha256_password(bin2hex(random_bytes(32))), $deposito, inout_uuid()]);
    if ($role !== null) $pdo->prepare('INSERT INTO RolUsuario (UsuariosId, RolesId) VALUES (?, ?)')->execute([$id, $role]);
    return ['id' => $id, 'mail' => $mail, 'token' => issue_token($id, $mail, $config['jwt_secret'], 1800)];
}
try {
    $porter = fixture_user('Seguridad', 'Cardales', 63);
    $sfUser = fixture_user('Seguridad SF', 'San Fernando', 63);
    $visitor = fixture_user('Destinatario', 'Cardales', 29);
    $third = fixture_user('Otro usuario', 'Cardales', 29);
    $admin = fixture_user('Admin sin seguridad', 'Cardales', 2);
    $sysadmin = fixture_user('SysAdmin sin seguridad', 'Cardales', 1);
    check_inout(http_inout('/inout-seguridad', $admin['token'])['status'] === 200, 'ADMIN puede acceder sin SEGURIDAD_INOUT');
    check_inout(http_inout('/inout-seguridad', $sysadmin['token'])['status'] === 200, 'SysAdmin puede acceder sin SEGURIDAD_INOUT');
    $noRole = fixture_user('Sin roles', 'Cardales', null);
    check_inout(http_inout('/inout-seguridad/notificaciones', $noRole['token'])['status'] === 200, 'usuario sin roles puede recibir notificaciones moviles');
    check_inout(http_inout('/push/registrar-token', $noRole['token'], 'POST', ['pushToken' => 'invalid', 'plataforma' => 'android'])['status'] === 400, 'usuario sin roles accede al registro push con validacion');
    check_inout(http_inout('/inout-seguridad', $noRole['token'])['status'] === 403, 'usuario sin permisos no accede a porteria');
    check_inout(http_inout('/inout-seguridad/notificaciones', null, 'GET', null, true, $noRole['id'])['status'] === 200, 'usuario web sin roles puede recibir notificaciones');
    check_inout(http_inout('/inout-seguridad/notificaciones', null, 'GET', null, true, $noRole['id'], false)['status'] === 401, 'rechaza puente web sin firma valida');
    check_inout(http_inout('/inout-seguridad', null)['status'] === 401, 'rechaza sin token');
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'GET', null, false)['status'] === 401, 'rechaza sin API key');
    check_inout(http_inout('/inout-seguridad', $visitor['token'])['status'] === 403, 'EMPLEADO no puede consultar porteria');
    check_inout(http_inout('/push/registrar-token', $visitor['token'], 'POST', ['pushToken' => 'invalid', 'plataforma' => 'android'])['status'] === 400, 'EMPLEADO puede acceder al registro push y se valida su token');
    check_inout(http_inout('/inout-seguridad', 'invalid')['status'] === 401, 'rechaza token adulterado');
    foreach (['motivos', 'empresas', 'usuarios'] as $resource) {
        $r = http_inout('/inout-seguridad/opciones?recurso=' . $resource, $porter['token']);
        check_inout($r['status'] === 200 && is_array($r['body']['opciones']), 'catalogo ' . $resource);
    }
    $transaction = inout_uuid();
    $body = ['transaccionId' => $transaction, 'tipoPersona' => 'Invitado', 'movimiento' => 'Ingreso', 'horario' => '09:30',
        'motivoId' => '20a00000-0000-4000-8000-000000000003', 'empresaOtra' => 'Empresa de prueba', 'personas' => [
            ['nombre' => 'Ana', 'apellido' => 'Prueba', 'dni' => '99000001'], ['nombre' => 'Luis', 'apellido' => 'Prueba', 'dni' => '99000002']]];
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $body)['status'] === 422, 'invitados requieren destinatario');
    $body['usuarioVisitaId'] = $visitor['id'];
    $bad = $body; $bad['horario'] = '24:00';
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $bad)['status'] === 422, 'valida HH:mm');
    $bad = $body; $bad['personas'][] = $bad['personas'][0];
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $bad)['status'] === 422, 'rechaza DNI duplicado');
    $bad = $body; $bad['motivoId'] = inout_uuid();
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $bad)['status'] === 422, 'valida motivo en servidor');
    $bad = $body; $bad['empresaId'] = inout_uuid();
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $bad)['status'] === 422, 'rechaza empresa inexistente');
    $bad = $body; $bad['usuarioVisitaId'] = inout_uuid();
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $bad)['status'] === 422, 'rechaza destinatario inexistente');
    $bad = $body; $bad['personas'][1]['nombre'] = '';
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $bad)['status'] === 422, 'rechaza lote con persona incompleta');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM InOutSeguridad WHERE TransaccionId = ?'); $stmt->execute([$transaction]);
    check_inout((int) $stmt->fetchColumn() === 0, 'lote invalido no guarda filas parciales');
    $body['Sede'] = 'San Fernando'; $body['UserAdd'] = $third['id'];
    $r = http_inout('/inout-seguridad', $porter['token'], 'POST', $body);
    check_inout($r['status'] === 201 && $r['body']['cantidad'] === 2, 'carga multiple atomica');
    check_inout($r['body']['notificacionPendiente'] === true, 'sin token push queda pendiente');
    $stmt = $pdo->prepare('SELECT * FROM InOutSeguridad WHERE TransaccionId = ?'); $stmt->execute([$transaction]); $rows = $stmt->fetchAll();
    check_inout(count($rows) === 2 && $rows[0]['Sede'] === 'Cardales' && $rows[0]['UserAdd'] === $porter['id'] && (int) $rows[0]['Estado'] === 1 && $rows[0]['DateAdd'] !== null, 'auditoria y sede del servidor');
    $r = http_inout('/inout-seguridad', $porter['token'], 'POST', $body);
    check_inout($r['status'] === 200 && $r['body']['cantidad'] === 2, 'reintento idempotente');
    check_inout(http_inout('/inout-seguridad', $sfUser['token'], 'POST', $body)['status'] === 409, 'impide reutilizar carga de otro usuario');
    $r = http_inout('/inout-seguridad/notificaciones', $visitor['token']);
    $notification = $r['body']['notificaciones'][0] ?? [];
    check_inout(($notification['title'] ?? '') === 'Ingreso de Invitado' && str_contains($notification['body'] ?? '', 'Ana Prueba') && str_contains($notification['body'] ?? '', 'Luis Prueba'), 'notificacion con listado completo');
    $web = http_inout('/inout-seguridad/notificaciones', null, 'GET', null, true, $visitor['id']);
    check_inout($web['status'] === 200 && $web['body']['notificaciones'][0]['transaccionId'] === $transaction, 'destinatario web recibe visita sin rol de seguridad');
    http_inout('/inout-seguridad/leida', $third['token'], 'POST', ['transaccionId' => $transaction]);
    check_inout(count(http_inout('/inout-seguridad/notificaciones', $visitor['token'])['body']['notificaciones']) === 1, 'impide lectura ajena');
    http_inout('/inout-seguridad/leida', $visitor['token'], 'POST', ['transaccionId' => $transaction]);
    check_inout(http_inout('/inout-seguridad/notificaciones', $visitor['token'])['body']['notificaciones'] === [], 'confirma lectura del destinatario');
    $pdo->prepare('UPDATE InOutSeguridad SET Estado=3 WHERE TransaccionId=?')->execute([$transaction]);
    $deleted = http_inout('/inout-seguridad', $porter['token']);
    check_inout(array_intersect(array_column($deleted['body']['registros'], 'id'), array_column($rows, 'Id')) === [], 'listado excluye eliminados');
    $body['transaccionId'] = inout_uuid(); $body['movimiento'] = 'Egreso'; $body['tipoPersona'] = 'Externo';
    http_inout('/inout-seguridad', $porter['token'], 'POST', $body);
    check_inout(http_inout('/inout-seguridad/notificaciones', $visitor['token'])['body']['notificaciones'][0]['title'] === 'Egreso de Invitado', 'externo notifica egreso');
    $web = http_inout('/inout-seguridad/leida', null, 'POST', ['transaccionId' => $body['transaccionId']], true, $visitor['id']);
    check_inout($web['status'] === 200 && http_inout('/inout-seguridad/notificaciones', $visitor['token'])['body']['notificaciones'] === [], 'lectura web sincroniza y detiene alerta movil');
    $body['transaccionId'] = inout_uuid(); unset($body['usuarioVisitaId']);
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $body)['status'] === 201, 'externo sin destinatario');
    $body['personas'] = [];
    for ($i = 3; $i <= 24; $i++) $body['personas'][] = ['nombre' => 'Persona', 'apellido' => 'Prueba ' . $i, 'dni' => (string) (99000000 + $i)];
    $body['transaccionId'] = inout_uuid();
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $body)['status'] === 201, 'carga de 22 personas');
    $r = http_inout('/inout-seguridad?pagina=1', $porter['token']);
    check_inout(count($r['body']['registros']) === 20, 'pagina de 20');
    $r2 = http_inout('/inout-seguridad?pagina=2', $porter['token']);
    check_inout(count($r2['body']['registros']) > 0 && array_intersect(array_column($r['body']['registros'], 'id'), array_column($r2['body']['registros'], 'id')) === [], 'segunda pagina sin duplicados');
    $sf = http_inout('/inout-seguridad', $sfUser['token']);
    check_inout(array_intersect(array_column($sf['body']['registros'], 'id'), array_column($rows, 'Id')) === [], 'separa sedes');
    $employee = $pdo->query('SELECT Legajo, Nombre FROM Personal WHERE Estado = 1 ORDER BY Legajo LIMIT 1')->fetch();
    if ($employee) {
        $r = http_inout('/inout-seguridad/personal?legajo=' . $employee['Legajo'], $porter['token']);
        check_inout($r['status'] === 200 && $r['body']['personal']['nombre'] === $employee['Nombre'], 'obtiene Personal por legajo');
        $tid = inout_uuid();
        $r = http_inout('/inout-seguridad', $porter['token'], 'POST', ['transaccionId' => $tid, 'tipoPersona' => 'RAEI', 'movimiento' => 'Ingreso', 'legajo' => $employee['Legajo'], 'horario' => '08:00', 'motivoId' => '20a00000-0000-4000-8000-000000000001', 'nombre' => 'Manipulado']);
        $s = $pdo->prepare('SELECT Nombre FROM InOutSeguridad WHERE TransaccionId = ?'); $s->execute([$tid]);
        check_inout($r['status'] === 201 && $s->fetchColumn() === $employee['Nombre'], 'RAEI usa identidad del servidor');
    }
    echo "TOTAL: $checks verificaciones correctas." . PHP_EOL;
} finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE FROM InOutSeguridad WHERE UserAdd = ? OR UsuarioVisitaId = ?')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM RolUsuario WHERE UsuariosId = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM Usuarios WHERE Id = ?')->execute([$id]);
        }
        echo 'Fixtures eliminadas.' . PHP_EOL;
}
