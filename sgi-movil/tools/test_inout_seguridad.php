<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/inout_seguridad.php';
require_once dirname(__DIR__) . '/lib/notas_traslado.php';
$config = require dirname(__DIR__) . '/config.php';
$pdo = db_connect($config);
$testMotivos = $pdo->query('SELECT Id FROM InOutSeguridadMotivo WHERE Estado = 1 ORDER BY Id LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
if (count($testMotivos) < 3) throw new RuntimeException('La prueba requiere tres motivos activos.');
// Todas las horas de las fixtures quedan en el pasado, conservando su orden relativo.
$testClock = new DateTimeImmutable('now', novedades_timezone());
$testMinutes = max(0, (int) $testClock->format('H') * 60 + (int) $testClock->format('i') - 1);
function hora_inout_prueba(string $hora): string {
    global $testMinutes;
    [$hours, $minutes] = array_map('intval', explode(':', $hora));
    $scaled = intdiv(($hours * 60 + $minutes) * $testMinutes, 1440);
    return sprintf('%02d:%02d', intdiv($scaled, 60), $scaled % 60);
}
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
    check_inout(inout_text_length("José\n😀") === 6, 'longitud UTF-8 con acentos, salto y emoji');
    check_inout(inout_text_cut('aé😀z', 4) === 'aé', 'limite de bytes no parte emoji');
    check_inout(inout_text_cut('aé😀z', 2) === 'a', 'limite de bytes no parte acento');
    check_inout(inout_text_cut('aé😀z', 7) === 'aé😀', 'conserva caracteres completos en el limite');
    $porter = fixture_user('Seguridad', 'Cardales', 63);
    $sfUser = fixture_user('Seguridad SF', 'San Fernando', 63);
    $visitor = fixture_user('Destinatario', 'Cardales', 29);
    $third = fixture_user('Otro usuario', 'Cardales', 29);
    $admin = fixture_user('Admin sin seguridad', 'Cardales', 2);
    $sysadmin = fixture_user('SysAdmin sin seguridad', 'Cardales', 1);
    check_inout(http_inout('/inout-seguridad', $admin['token'])['status'] === 200, 'ADMIN puede acceder sin SEGURIDAD_INOUT');
    check_inout(http_inout('/inout-seguridad', $sysadmin['token'])['status'] === 200, 'SysAdmin puede acceder sin SEGURIDAD_INOUT');
    $noRole = fixture_user('Sin roles', 'Cardales', null);
    check_inout(http_inout('/notas-traslado?resumenPendientes=true', $noRole['token'])['status'] === 403, 'resumen de traslados respeta permisos');
    $summary = http_inout('/notas-traslado?resumenPendientes=true', $admin['token']);
    $summaryContext = ['roles' => ['ADMIN'], 'usuarioId' => $admin['id'], 'usuarioMail' => $admin['mail'], 'depositoId' => ''];
    $expectedPending = 0;
    $summaryRows = $pdo->query(notas_traslado_base_select() . ' WHERE n.Estado = 1 AND n.StatusNotaTrasladoId IN (1,2,3,4,5,8)');
    while ($summaryRow = $summaryRows->fetch(PDO::FETCH_ASSOC)) {
        if (notas_traslado_tiene_tarea_pendiente($summaryRow, $summaryContext)) $expectedPending++;
    }
    check_inout($summary['status'] === 200 && ($summary['body']['pendientes'] ?? null) === $expectedPending,
        'resumen servido incluye todas las notas accionables sin limite de 200 ni filtro de fechas');
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
    $body = ['transaccionId' => $transaction, 'tipoPersona' => 'Invitado', 'movimiento' => 'Ingreso', 'horario' => hora_inout_prueba('09:30'),
        'motivoId' => $testMotivos[2], 'empresaOtra' => 'Empresa de prueba', 'nota' => "Trae documentación.\nAvisar a recepción.", 'personas' => [
            ['nombre' => 'Ana', 'apellido' => 'Prueba', 'dni' => '99000001'], ['nombre' => 'Luis', 'apellido' => 'Prueba', 'dni' => '99000002']]];
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $body)['status'] === 422, 'invitados requieren destinatario');
    $body['usuarioVisitaId'] = $visitor['id'];
    $clock = new DateTimeImmutable('now', novedades_timezone());
    if ($clock->format('H:i') !== '23:59') {
        $futureTime = $clock->modify('+1 minute')->format('H:i');
        foreach (['RAEI', 'Externo', 'Invitado'] as $personType) foreach (['Ingreso', 'Egreso'] as $movement) {
            $future = $body; $future['transaccionId'] = inout_uuid(); $future['horario'] = $futureTime;
            $future['tipoPersona'] = $personType; $future['movimiento'] = $movement;
            if ($movement === 'Egreso' && $personType !== 'RAEI') $future['ingresosIds'] = [inout_uuid()];
            $response = http_inout('/inout-seguridad', $porter['token'], 'POST', $future);
            check_inout($response['status'] === 422 && str_contains($response['body']['message'] ?? '', 'posterior a la hora actual'),
                'rechaza horario futuro ' . $personType . ' ' . $movement);
            $stored = $pdo->prepare('SELECT COUNT(*) FROM InOutSeguridad WHERE TransaccionId = ?'); $stored->execute([$future['transaccionId']]);
            check_inout((int) $stored->fetchColumn() === 0, 'horario futuro no guarda movimientos ' . $personType . ' ' . $movement);
        }
    }
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
    check_inout(($notification['title'] ?? '') === 'Ingreso de Visita' && str_contains($notification['body'] ?? '', 'Ana Prueba') && str_contains($notification['body'] ?? '', 'Luis Prueba'), 'notificacion con listado completo');
    check_inout(($notification['empresa'] ?? '') === 'Empresa de prueba'
        && str_contains($notification['body'], "Empresa: Empresa de prueba\n\nEsta ingresando a la empresa:\n✓ Ana Prueba\n✓ Luis Prueba"), 'notificación con empresa y todas las personas en renglones separados');
    check_inout(($notification['fechaHora'] ?? '') === date('d/m/Y', strtotime($rows[0]['DateAdd'])) . ' ' . $body['horario'] . ' hs.'
        && ($notification['nota'] ?? null) === $body['nota'], 'API entrega fecha, hora elegida y nota guardada');
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
    check_inout(http_inout('/inout-seguridad/notificaciones', $visitor['token'])['body']['notificaciones'][0]['title'] === 'Salida de Personal Externo', 'externo notifica egreso');
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
        $r = http_inout('/inout-seguridad', $porter['token'], 'POST', ['transaccionId' => $tid, 'tipoPersona' => 'RAEI', 'movimiento' => 'Ingreso', 'legajo' => $employee['Legajo'], 'horario' => hora_inout_prueba('08:00'), 'motivoId' => $testMotivos[0], 'nombre' => 'Manipulado']);
        $s = $pdo->prepare('SELECT Nombre FROM InOutSeguridad WHERE TransaccionId = ?'); $s->execute([$tid]);
        check_inout($r['status'] === 201 && $s->fetchColumn() === $employee['Nombre'], 'RAEI usa identidad del servidor');
    }
    // Personal RAEI: identidad individual y guardado atómico del lote completo.
    $employees = $pdo->query('SELECT MIN(Id) AS Id, Legajo, MIN(Dni) AS Dni FROM Personal WHERE Estado = 1 AND Legajo BETWEEN 1 AND 9999 GROUP BY Legajo HAVING COUNT(*) = 1 ORDER BY Legajo')->fetchAll();
    $batchEmployees = []; $batchDnis = [];
    foreach ($employees as $e) {
        if (isset($batchDnis[$e['Dni']])) continue;
        $batchEmployees[] = $e; $batchDnis[$e['Dni']] = true;
        if (count($batchEmployees) === 2) break;
    }
    check_inout(count($batchEmployees) === 2, 'hay dos empleados activos para probar carga multiple');
    $legajos = array_map('intval', array_column($batchEmployees, 'Legajo'));
    $batch = ['tipoPersona' => 'RAEI', 'horario' => hora_inout_prueba('08:00'), 'motivoId' => $testMotivos[0], 'nota' => 'Prueba lote RAEI', 'legajos' => $legajos];
    foreach (['Ingreso', 'Egreso'] as $movement) {
        $batch['movimiento'] = $movement; $batch['transaccionId'] = inout_uuid();
        $r = http_inout('/inout-seguridad', $porter['token'], 'POST', $batch);
        check_inout($r['status'] === 201 && $r['body']['cantidad'] === 2, 'RAEI multiple ' . $movement);
        $s = $pdo->prepare('SELECT PersonalId, Legajo, Dni, Movimiento, Horario, MotivoId, Nota FROM InOutSeguridad WHERE TransaccionId = ? ORDER BY Legajo');
        $s->execute([$batch['transaccionId']]); $stored = $s->fetchAll();
        check_inout(count($stored) === 2, 'dos filas individuales ' . $movement);
        foreach ($stored as $i => $row) {
            check_inout($row['PersonalId'] === $batchEmployees[$i]['Id'] && (int) $row['Legajo'] === $legajos[$i] && $row['Dni'] === $batchEmployees[$i]['Dni']
                && $row['Movimiento'] === $movement && $row['Horario'] === $batch['horario'] . ':00' && $row['MotivoId'] === $batch['motivoId'] && $row['Nota'] === $batch['nota'], 'identidad y datos individuales RAEI ' . $movement . ' ' . $i);
        }
        $r = http_inout('/inout-seguridad', $porter['token'], 'POST', $batch);
        $s->execute([$batch['transaccionId']]);
        check_inout($r['status'] === 200 && $r['body']['cantidad'] === 2 && count($s->fetchAll()) === 2, 'reintento sin duplicados RAEI ' . $movement);
    }
    $unavailable = 9999;
    while (in_array($unavailable, array_map('intval', array_column($employees, 'Legajo')), true)) $unavailable--;
    foreach ([[], null, '123', [$legajos[0], $legajos[0]], [$legajos[0], str_pad((string) $legajos[0], 4, '0', STR_PAD_LEFT)], [$legajos[0], $unavailable], [$legajos[0], 0], [$legajos[0], true], [$legajos[0], '1e2'], array_fill(0, 51, $legajos[0])] as $invalid) {
        $bad = $batch; $bad['transaccionId'] = inout_uuid(); $bad['legajos'] = $invalid;
        $r = http_inout('/inout-seguridad', $porter['token'], 'POST', $bad);
        check_inout($r['status'] === 422, 'rechaza lote RAEI invalido ' . json_encode($invalid));
        $s->execute([$bad['transaccionId']]);
        check_inout(count($s->fetchAll()) === 0, 'lote RAEI invalido no guarda filas parciales');
    }
    // Egresos desde ingresos: un lote puede mezclar empresas, motivos y visitas.
    $entryIds = [];
    foreach (['Externo', 'Invitado'] as $kind) {
        $in = ['transaccionId' => inout_uuid(), 'tipoPersona' => $kind, 'movimiento' => 'Ingreso', 'horario' => hora_inout_prueba('09:30'),
            'motivoId' => $testMotivos[2], 'empresaOtra' => 'Empresa selección ' . $kind,
            'usuarioVisitaId' => $visitor['id'], 'personas' => [['nombre' => 'José', 'apellido' => 'Selección', 'dni' => $kind === 'Externo' ? '98000001' : '98000002']]];
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $in)['status'] === 201, 'ingreso seleccionable ' . $kind);
        http_inout('/inout-seguridad/leida', $visitor['token'], 'POST', ['transaccionId' => $in['transaccionId']]);
        $stmt = $pdo->prepare('SELECT * FROM InOutSeguridad WHERE TransaccionId = ?'); $stmt->execute([$in['transaccionId']]); $original = $stmt->fetch();
        $entryIds[$kind] = $original['Id'];
        $url = '/inout-seguridad/presentes?tipoPersona=' . $kind . '&q=9800000';
        $p = http_inout($url, $porter['token']);
        check_inout($p['status'] === 200 && in_array($original['Id'], array_column($p['body']['personas'], 'id')), 'selector incluye ingreso presente ' . $kind);
        check_inout(!in_array($original['Id'], array_column(http_inout($url, $sfUser['token'])['body']['personas'], 'id')), 'selector aísla sede ' . $kind);
        $out = ['transaccionId' => inout_uuid(), 'tipoPersona' => $kind, 'movimiento' => 'Egreso', 'horario' => hora_inout_prueba('18:45'), 'ingresosIds' => [$original['Id']],
            'nombre' => 'Alterado', 'empresaOtra' => 'Alterada', 'motivoId' => inout_uuid(), 'usuarioVisitaId' => $third['id']];
        $wrong = $out; $wrong['tipoPersona'] = $kind === 'Externo' ? 'Invitado' : 'Externo';
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $wrong)['status'] === 409, 'rechaza ingreso de otro tipo ' . $kind);
        check_inout(http_inout('/inout-seguridad', $sfUser['token'], 'POST', $out)['status'] === 409, 'rechaza ingreso de otra sede ' . $kind);
        $bad = $out; $bad['ingresosIds'][] = inout_uuid();
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $bad)['status'] === 409, 'egreso inválido no guarda lote parcial ' . $kind);
        $stmt->execute([$out['transaccionId']]); check_inout($stmt->fetch() === false, 'sin filas parciales ' . $kind);
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $out)['status'] === 201, 'egreso sin recargar identidad empresa motivo ' . $kind);
        $stmt->execute([$out['transaccionId']]); $saved = $stmt->fetch();
        check_inout($saved['IngresoId'] === $original['Id'] && $saved['Dni'] === $original['Dni'] && $saved['Nombre'] === $original['Nombre']
            && $saved['EmpresaNombre'] === $original['EmpresaNombre'] && $saved['MotivoId'] === $original['MotivoId']
            && $saved['UsuarioVisitaId'] === $original['UsuarioVisitaId'], 'datos heredados del servidor ' . $kind);
        check_inout(!in_array($original['Id'], array_column(http_inout($url, $porter['token'])['body']['personas'], 'id')), 'persona retirada desaparece del selector ' . $kind);
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $out)['status'] === 200, 'reintento de egreso sin duplicar ' . $kind);
        $duplicate = $out; $duplicate['transaccionId'] = inout_uuid();
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $duplicate)['status'] === 409, 'impide segunda salida del mismo ingreso ' . $kind);
        $notification = http_inout('/inout-seguridad/notificaciones', $visitor['token'])['body']['notificaciones'];
        check_inout(count(array_filter($notification, static fn($n) => $n['transaccionId'] === $out['transaccionId'] && $n['title'] === ($kind === 'Externo' ? 'Salida de Personal Externo' : 'Salida de Visita'))) === 1, 'notifica al visitado original ' . $kind);
        // Un reingreso del mismo DNI debe estar disponible otra vez.
        $in['transaccionId'] = inout_uuid(); $in['horario'] = hora_inout_prueba('19:00');
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $in)['status'] === 201, 'reingreso ' . $kind);
        $stmt->execute([$in['transaccionId']]); $reentry = $stmt->fetch();
        check_inout(in_array($reentry['Id'], array_column(http_inout($url, $porter['token'])['body']['personas'], 'id')), 'selector ofrece reingreso ' . $kind);
    }
    check_inout(http_inout('/inout-seguridad/presentes?tipoPersona=RAEI', $porter['token'])['status'] === 422, 'presentes limita tipos externos');
    check_inout(http_inout('/inout-seguridad/presentes?tipoPersona=Externo', $noRole['token'])['status'] === 403, 'presentes requiere permiso de seguridad');
    $batchIds = [];
    foreach ([$visitor, $third] as $index => $recipient) {
        $in = ['transaccionId' => inout_uuid(), 'tipoPersona' => 'Invitado', 'movimiento' => 'Ingreso', 'horario' => hora_inout_prueba('10:00'),
            'motivoId' => $testMotivos[$index], 'empresaOtra' => 'Empresa ' . $index,
            'usuarioVisitaId' => $recipient['id'], 'personas' => [['nombre' => 'Persona ' . $index, 'apellido' => 'Lote', 'dni' => (string) (97800001 + $index)]]];
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $in)['status'] === 201, 'ingreso para egreso mixto ' . $index);
        $stmt = $pdo->prepare('SELECT Id FROM InOutSeguridad WHERE TransaccionId = ?'); $stmt->execute([$in['transaccionId']]); $batchIds[] = $stmt->fetchColumn();
    }
    $out = ['transaccionId' => inout_uuid(), 'tipoPersona' => 'Invitado', 'movimiento' => 'Egreso', 'horario' => hora_inout_prueba('16:30'), 'ingresosIds' => $batchIds];
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $out)['status'] === 201, 'egreso múltiple con empresas motivos destinatarios distintos');
    foreach ([$visitor, $third] as $index => $recipient) {
        $notes = http_inout('/inout-seguridad/notificaciones', $recipient['token'])['body']['notificaciones'];
        $notes = array_values(array_filter($notes, static fn($n) => $n['transaccionId'] === $out['transaccionId']));
        check_inout(count($notes) === 1 && count($notes[0]['personas']) === 1 && $notes[0]['personas'][0]['nombre'] === 'Persona ' . $index, 'cada destinatario ve solo su visita ' . $index);
        check_inout(($notes[0]['empresa'] ?? '') === 'Empresa ' . $index && str_contains($notes[0]['body'], "\n✓ Persona " . $index . ' Lote'), 'empresa y persona corresponden al destinatario ' . $index);
        check_inout(($notes[0]['nota'] ?? null) === '', 'registro sin nota entrega texto vacío ' . $index);
    }
    $legacy = ['transaccionId' => inout_uuid(), 'tipoPersona' => 'Externo', 'movimiento' => 'Ingreso', 'horario' => hora_inout_prueba('20:00'),
        'motivoId' => $testMotivos[0], 'empresaOtra' => 'Empresa nocturna',
        'personas' => [['nombre' => 'Turno', 'apellido' => 'Nocturno', 'dni' => '97700001']]];
    http_inout('/inout-seguridad', $porter['token'], 'POST', $legacy);
    $pdo->prepare('UPDATE InOutSeguridad SET DateAdd = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE TransaccionId = ?')->execute([$legacy['transaccionId']]);
    $stmt->execute([$legacy['transaccionId']]); $nightId = $stmt->fetchColumn();
    $url = '/inout-seguridad/presentes?tipoPersona=Externo&q=97700001';
    check_inout(in_array($nightId, array_column(http_inout($url, $porter['token'])['body']['personas'], 'id')), 'presentes incluye ingreso de día anterior');
    $legacy['transaccionId'] = inout_uuid(); $legacy['movimiento'] = 'Egreso'; $legacy['horario'] = hora_inout_prueba('06:00');
    http_inout('/inout-seguridad', $porter['token'], 'POST', $legacy);
    check_inout(!in_array($nightId, array_column(http_inout($url, $porter['token'])['body']['personas'], 'id')), 'egreso de app anterior cierra presencia nocturna');
    // El filtro usa la fecha de carga, también para el total y las páginas.
    $dateIds = [];
    foreach (['2001-02-03 00:00:00', '2001-02-03 23:59:59', '2001-02-04 00:00:00'] as $index => $stamp) {
        $dated = $legacy; $dated['transaccionId'] = inout_uuid(); $dated['movimiento'] = 'Ingreso';
        $dated['personas'][0]['dni'] = (string) (97600001 + $index);
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $dated)['status'] === 201, 'crea movimiento para filtro de fecha ' . $index);
        $pdo->prepare('UPDATE InOutSeguridad SET DateAdd = ? WHERE TransaccionId = ?')->execute([$stamp, $dated['transaccionId']]);
        $stmt->execute([$dated['transaccionId']]); $dateIds[] = $stmt->fetchColumn();
    }
    $dated = http_inout('/inout-seguridad?fecha=2001-02-03', $porter['token']);
    $listed = array_column($dated['body']['registros'], 'id');
    check_inout($dated['status'] === 200 && in_array($dateIds[0], $listed) && in_array($dateIds[1], $listed) && !in_array($dateIds[2], $listed), 'incluye todo el día y excluye medianoche siguiente');
    check_inout($dated['body']['fecha'] === '2001-02-03' && $dated['body']['total'] === 2, 'total filtrado por fecha');
    $nextPage = http_inout('/inout-seguridad?fecha=2001-02-03&pagina=2', $porter['token']);
    check_inout($nextPage['body']['registros'] === [] && $nextPage['body']['total'] === 2, 'paginación conserva filtro de fecha');
    $today = http_inout('/inout-seguridad', $porter['token']);
    check_inout($today['body']['fecha'] === date('Y-m-d') && array_intersect($dateIds, array_column($today['body']['registros'], 'id')) === [], 'fecha predeterminada es hoy');
    check_inout(http_inout('/inout-seguridad?fecha=2001-02-03', $sfUser['token'])['body']['total'] === 0, 'filtro por fecha respeta sede');
    foreach (['2026-02-30', '21-10-2026', ''] as $invalidDate) {
        check_inout(http_inout('/inout-seguridad?fecha=' . $invalidDate, $porter['token'])['status'] === 422, 'rechaza fecha inválida ' . $invalidDate);
    }
    // Un registro físico por persona, con lecturas y destinatarios independientes.
    $multi = ['transaccionId' => inout_uuid(), 'tipoPersona' => 'Invitado', 'movimiento' => 'Ingreso', 'horario' => hora_inout_prueba('10:30'),
        'motivoId' => $testMotivos[0], 'empresaOtra' => 'Empresa múltiples destinatarios',
        'usuariosVisitaIds' => [$visitor['id'], $third['id'], strtoupper($visitor['id'])],
        'personas' => [['nombre' => 'Doble', 'apellido' => 'Uno', 'dni' => '97500001'], ['nombre' => 'Doble', 'apellido' => 'Dos', 'dni' => '97500002']]];
    $badMulti = $multi; $badMulti['usuariosVisitaIds'] = 'invalido';
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $badMulti)['status'] === 422, 'destinatarios debe ser un listado');
    $badMulti['usuariosVisitaIds'] = [$visitor['id'], inout_uuid()];
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $badMulti)['status'] === 422, 'rechaza lote con destinatario inexistente');
    $badMulti['usuariosVisitaIds'] = [];
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $badMulti)['status'] === 422, 'invitado requiere al menos un destinatario');
    $badMulti['usuariosVisitaIds'] = array_fill(0, 51, $visitor['id']);
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $badMulti)['status'] === 422, 'limita listado a 50 destinatarios');
    $r = http_inout('/inout-seguridad', $porter['token'], 'POST', $multi);
    check_inout($r['status'] === 201 && $r['body']['cantidad'] === 2, 'dos personas para varios destinatarios no duplican movimientos');
    $links = $pdo->prepare('SELECT COUNT(*) FROM InOutSeguridadDestinatario d JOIN InOutSeguridad i ON i.Id=d.InOutSeguridadId WHERE i.TransaccionId=?');
    $links->execute([$multi['transaccionId']]);
    check_inout((int) $links->fetchColumn() === 4, 'destinatarios repetidos se deduplican por persona');
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $multi)['status'] === 200, 'reintento de carga múltiple es idempotente');
    foreach ([$visitor, $third] as $recipient) {
        $notes = http_inout('/inout-seguridad/notificaciones', $recipient['token'])['body']['notificaciones'];
        $notes = array_values(array_filter($notes, static fn($n) => $n['transaccionId'] === $multi['transaccionId']));
        check_inout(count($notes) === 1 && count($notes[0]['personas']) === 2, 'cada destinatario recibe el listado completo');
    }
    http_inout('/inout-seguridad/leida', null, 'POST', ['transaccionId' => $multi['transaccionId']], true, $visitor['id']);
    $due = array_values(array_filter(inout_destinatarios_pendientes($pdo, null, 0), static fn($n) => $n['TransaccionId'] === $multi['transaccionId']));
    check_inout(count($due) === 1 && $due[0]['UsuarioVisitaId'] === $third['id'], 'leer desde web detiene solo los recordatorios del lector');
    check_inout(inout_notificaciones_usuario($pdo, $visitor['id'], $multi['transaccionId']) === [], 'lector deja de ver el ingreso');
    check_inout(count(inout_notificaciones_usuario($pdo, $third['id'], $multi['transaccionId'])) === 2, 'otro destinatario sigue pendiente tras lectura ajena');
    $readerHistory = http_inout('/inout-seguridad/historial/' . $multi['transaccionId'], $visitor['token']);
    $otherHistory = http_inout('/inout-seguridad/historial/' . $multi['transaccionId'], $third['token']);
    check_inout($readerHistory['status'] === 200 && $readerHistory['body']['novedad']['leida'] === true
        && $otherHistory['body']['novedad']['leida'] === false, 'historial conserva lecturas independientes de cada destinatario');
    check_inout(http_inout('/inout-seguridad/historial/' . $multi['transaccionId'], $noRole['token'])['status'] === 404, 'historial no expone visitas ajenas');
    $single = $multi; $single['transaccionId'] = inout_uuid(); $single['usuariosVisitaIds'] = [$third['id']];
    $single['personas'] = [['nombre' => 'Solo', 'apellido' => 'Tercero', 'dni' => '97500003']];
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $single)['status'] === 201, 'ingreso adicional con otro conjunto de destinatarios');
    $entryQuery = $pdo->prepare('SELECT Id FROM InOutSeguridad WHERE TransaccionId IN (?, ?)');
    $entryQuery->execute([$multi['transaccionId'], $single['transaccionId']]);
    $multiOut = ['transaccionId' => inout_uuid(), 'tipoPersona' => 'Invitado', 'movimiento' => 'Egreso', 'horario' => hora_inout_prueba('17:30'),
        'ingresosIds' => $entryQuery->fetchAll(PDO::FETCH_COLUMN), 'usuariosVisitaIds' => [$noRole['id']]];
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $multiOut)['status'] === 201, 'egreso mixto hereda todos los destinatarios de cada ingreso');
    check_inout(count(inout_notificaciones_usuario($pdo, $visitor['id'], $multiOut['transaccionId'])) === 2, 'egreso de primer destinatario incluye solo sus dos personas');
    check_inout(count(inout_notificaciones_usuario($pdo, $third['id'], $multiOut['transaccionId'])) === 3, 'egreso de segundo destinatario incluye sus tres personas');
    check_inout(inout_notificaciones_usuario($pdo, $noRole['id'], $multiOut['transaccionId']) === [], 'egreso ignora sustitución de destinatarios originales');
    http_inout('/inout-seguridad/leida', $third['token'], 'POST', ['transaccionId' => $multiOut['transaccionId']]);
    check_inout(count(inout_notificaciones_usuario($pdo, $visitor['id'], $multiOut['transaccionId'])) === 2, 'lectura de egreso de un usuario no confirma al resto');
    $news = http_inout('/novedades', null, 'POST', ['titulo' => 'Novedad de prueba', 'cuerpo' => "Primera línea.\nSegunda línea.",
        'from' => $porter['id'], 'usuarioIds' => [$visitor['id']]]);
    check_inout($news['status'] === 201, 'crea novedad dirigida a un destinatario de prueba');
    $newsId = $news['body']['novedadId'];
    $newsList = http_inout('/novedades', $visitor['token']);
    check_inout(in_array($newsId, array_column($newsList['body']['novedades'], 'id')) && $newsList['body']['noLeidas'] === 1, 'novedad pendiente disponible para ventana emergente');
    $newsDetail = http_inout('/novedades/' . $newsId, $visitor['token']);
    check_inout($newsDetail['body']['novedad']['leida'] === false && $newsDetail['body']['novedad']['cuerpo'] === "Primera línea.\nSegunda línea.", 'consultar contenido no confirma lectura');
    check_inout(http_inout('/novedades/' . $newsId, $third['token'])['status'] === 404, 'otro usuario no obtiene la novedad');
    $foreignRead = http_inout('/novedades/' . $newsId . '/leida', $third['token'], 'POST');
    check_inout($foreignRead['status'] === 404, 'otro usuario no confirma la novedad');
    $webNews = http_inout('/inout-seguridad/notificaciones', null, 'GET', null, true, $visitor['id']);
    check_inout(in_array($newsId, array_column($webNews['body']['novedades'], 'id')), 'puente web recibe novedades pendientes propias');
    $webForeign = http_inout('/inout-seguridad/notificaciones', null, 'GET', null, true, $third['id']);
    check_inout(!in_array($newsId, array_column($webForeign['body']['novedades'], 'id')), 'puente web no expone novedades ajenas');
    http_inout('/inout-seguridad/leida', null, 'POST', ['transaccionId' => $newsId], true, $third['id']);
    check_inout(http_inout('/novedades', $visitor['token'])['body']['noLeidas'] === 1, 'puente web ajeno no confirma lectura');
    check_inout(http_inout('/inout-seguridad/leida', null, 'POST', ['transaccionId' => $newsId], true, $visitor['id'])['body']['ok'] === true, 'ventana web confirma novedad');
    check_inout(http_inout('/novedades', $visitor['token'])['body']['noLeidas'] === 0, 'lectura web detiene alerta de novedad movil');
    check_inout(http_inout('/novedades/' . $newsId . '/leida', $visitor['token'], 'POST')['status'] === 200, 'confirmación explícita de lectura');
    check_inout(http_inout('/novedades/' . $newsId . '/leida', $visitor['token'], 'POST')['status'] === 200, 'reintento de lectura es idempotente');
    check_inout(http_inout('/novedades', $visitor['token'])['body']['noLeidas'] === 0, 'confirmar elimina novedad de alertas pendientes');
    // Historial mensual: integrar visitas sin reenviar ni duplicar alertas.
    check_inout(http_inout('/novedades?historial=true&anio=2026&mes=13', $visitor['token'])['status'] === 422, 'historial rechaza mes invalido');
    check_inout(http_inout('/novedades?historial=true&anio=abc&mes=2', $visitor['token'])['status'] === 422, 'historial rechaza año invalido');
    $historical = ['transaccionId' => inout_uuid(), 'tipoPersona' => 'Invitado', 'movimiento' => 'Ingreso', 'horario' => hora_inout_prueba('23:55'),
        'motivoId' => $testMotivos[0], 'empresaOtra' => 'Empresa historial', 'nota' => 'Documentación histórica',
        'usuarioVisitaId' => $visitor['id'], 'personas' => [['nombre' => 'Historia', 'apellido' => 'Prueba', 'dni' => '96990001']]];
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $historical)['status'] === 201, 'crea visita para historial mensual');
    $pdo->prepare('UPDATE InOutSeguridad SET DateAdd = ? WHERE TransaccionId = ?')->execute(['2001-02-28 23:59:59', $historical['transaccionId']]);
    $pdo->prepare('UPDATE MovilNovedad SET CreadoEn = ? WHERE Id = ?')->execute(['2001-02-01 00:00:00', $newsId]);
    $historyPath = '/novedades?historial=true&anio=2001&mes=2';
    $history = http_inout($historyPath, $visitor['token']);
    $historyId = 'visita-' . $historical['transaccionId'];
    check_inout($history['status'] === 200 && in_array($historyId, array_column($history['body']['novedades'], 'id'))
        && in_array($newsId, array_column($history['body']['novedades'], 'id')), 'mes historico incluye novedades y visitas en ambos extremos del mes');
    check_inout(in_array(2001, $history['body']['aniosDisponibles']), 'selector ofrece años con historial propio');
    $visitItem = http_inout('/inout-seguridad/historial/' . $historical['transaccionId'], $visitor['token'])['body']['novedad'];
    check_inout($visitItem['leida'] === false && str_contains($visitItem['cuerpo'], 'Documentación histórica')
        && str_contains($visitItem['cuerpo'], 'Historia Prueba'), 'detalle conserva nota y nombres sin marcar lectura por consultar');
    http_inout('/inout-seguridad/leida', $visitor['token'], 'POST', ['transaccionId' => $historical['transaccionId']]);
    $afterRead = http_inout('/inout-seguridad/historial/' . $historical['transaccionId'], $visitor['token'])['body']['novedad'];
    check_inout($afterRead['leida'] === true && $afterRead['leidaEn'] !== null, 'leer visita detiene alerta pero conserva historial');
    $march = http_inout('/novedades?historial=true&anio=2001&mes=3', $visitor['token']);
    check_inout(!in_array($historyId, array_column($march['body']['novedades'], 'id')), 'visita no se repite en mes siguiente');
    $foreignHistory = http_inout($historyPath, $third['token']);
    check_inout(!in_array($historyId, array_column($foreignHistory['body']['novedades'], 'id'))
        && !in_array($newsId, array_column($foreignHistory['body']['novedades'], 'id')), 'historial mensual respeta destinatarios');
    $pdo->prepare('DELETE d FROM InOutSeguridadDestinatario d INNER JOIN InOutSeguridad i ON i.Id = d.InOutSeguridadId WHERE i.TransaccionId = ?')
        ->execute([$historical['transaccionId']]);
    $legacyHistory = http_inout('/inout-seguridad/historial/' . $historical['transaccionId'], $visitor['token']);
    check_inout($legacyHistory['status'] === 200 && $legacyHistory['body']['novedad']['leida'] === true,
        'historial recupera avisos antiguos sin tabla de destinatarios por registro');
    $bulkNews = $pdo->prepare('INSERT INTO MovilNovedad (Id, Titulo, Cuerpo, EnviadoPorUsuarioId, CreadoEn) VALUES (?, ?, ?, ?, ?)');
    $bulkRecipient = $pdo->prepare('INSERT INTO MovilNovedadUsuario (NovedadId, UsuarioId, LeidaEn) VALUES (?, ?, NULL)');
    for ($index = 0; $index < 105; $index++) {
        $bulkId = inout_uuid();
        $bulkNews->execute([$bulkId, 'Historial ' . $index, 'Mensaje de prueba sin push', $porter['id'], '2001-02-15 12:00:00']);
        $bulkRecipient->execute([$bulkId, $visitor['id']]);
    }
    $completeHistory = http_inout($historyPath, $visitor['token']);
    check_inout(count($completeHistory['body']['novedades']) === 107, 'historial mensual completo supera limite anterior de 100 sin truncar busqueda');
    $sameMinute = ['transaccionId' => inout_uuid(), 'tipoPersona' => 'Externo', 'movimiento' => 'Ingreso',
        'horario' => (new DateTimeImmutable('now', novedades_timezone()))->format('H:i'),
        'motivoId' => $testMotivos[0], 'empresaOtra' => 'Prueba horario actual',
        'personas' => [['nombre' => 'Horario', 'apellido' => 'Actual', 'dni' => '96990002']]];
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $sameMinute)['status'] === 201, 'acepta el minuto actual en API');
    $retry = $sameMinute; $retry['horario'] = '23:59';
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $retry)['status'] === 200, 'validacion horaria conserva reintentos de cargas ya guardadas');
    // Registrar otro teléfono no debe dejar al primero sin push.
    $devices = [bin2hex(random_bytes(32)), bin2hex(random_bytes(32))];
    foreach ($devices as $device) {
        check_inout(http_inout('/push/registrar-token', $noRole['token'], 'POST', ['pushToken' => $device, 'plataforma' => 'android'])['status'] === 200, 'registra dispositivo de usuario sin roles');
    }
    $deviceQuery = $pdo->prepare('SELECT COUNT(*) FROM MovilPushToken WHERE UsuarioId = ?');
    $deviceQuery->execute([$noRole['id']]);
    check_inout((int) $deviceQuery->fetchColumn() === 2, 'mantiene ambos teléfonos del mismo destinatario');
    http_inout('/push/registrar-token', $noRole['token'], 'POST', ['pushToken' => $devices[0], 'plataforma' => 'android']);
    $deviceQuery->execute([$noRole['id']]);
    check_inout((int) $deviceQuery->fetchColumn() === 2, 'registro repetido de dispositivo no duplica tokens');
    http_inout('/push/registrar-token', $third['token'], 'POST', ['pushToken' => $devices[0], 'plataforma' => 'android']);
    $deviceQuery->execute([$noRole['id']]);
    check_inout((int) $deviceQuery->fetchColumn() === 1, 'cambiar cuenta solo desvincula ese teléfono');
    $deviceQuery->execute([$third['id']]);
    check_inout((int) $deviceQuery->fetchColumn() === 1, 'teléfono compartido pertenece a la última cuenta');
    // Patente opcional individual: persistencia, edición al salir y compatibilidad.
    $plateRows = $pdo->prepare('SELECT Id, Dni, Legajo, Patente FROM InOutSeguridad WHERE TransaccionId = ? ORDER BY Dni');
    foreach (['Ingreso', 'Egreso'] as $movement) {
        $plateBatch = $batch;
        $plateBatch['movimiento'] = $movement; $plateBatch['transaccionId'] = inout_uuid();
        $plateBatch['patentesLegajos'] = [$legajos[0] => ' ab123cd ', $legajos[1] => ''];
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $plateBatch)['status'] === 201, 'RAEI con patente individual ' . $movement);
        $plateRows->execute([$plateBatch['transaccionId']]);
        $platesByLegajo = array_column($plateRows->fetchAll(), 'Patente', 'Legajo');
        check_inout($platesByLegajo[$legajos[0]] === 'AB123CD' && $platesByLegajo[$legajos[1]] === null, 'RAEI normaliza patente y permite peaton ' . $movement);
    }
    foreach (['Externo', 'Invitado'] as $kindIndex => $kind) {
        $plateEntry = ['transaccionId' => inout_uuid(), 'tipoPersona' => $kind, 'movimiento' => 'Ingreso',
            'horario' => hora_inout_prueba('08:00'), 'motivoId' => $testMotivos[0], 'empresaOtra' => 'Prueba patentes',
            'usuarioVisitaId' => $visitor['id'], 'personas' => []];
        foreach ([' abc-123 ', 'AB 123 CD', 'AA000BB'] as $i => $plate) {
            $plateEntry['personas'][] = ['nombre' => 'Patente', 'apellido' => 'Prueba', 'dni' => (string) (96888000 + $kindIndex * 10 + $i), 'patente' => $plate];
        }
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $plateEntry)['status'] === 201, 'ingreso con patentes ' . $kind);
        $plateRows->execute([$plateEntry['transaccionId']]); $entries = $plateRows->fetchAll();
        check_inout(array_column($entries, 'Patente') === ['ABC-123', 'AB 123 CD', 'AA000BB'], 'guarda patentes independientes ' . $kind);
        $present = http_inout('/inout-seguridad/presentes?tipoPersona=' . $kind . '&q=96888', $porter['token']);
        $presentPlates = array_column($present['body']['personas'], 'patente', 'id');
        check_inout(($presentPlates[$entries[0]['Id']] ?? null) === 'ABC-123', 'selector devuelve patente del ingreso ' . $kind);
        $plateExit = ['transaccionId' => inout_uuid(), 'tipoPersona' => $kind, 'movimiento' => 'Egreso',
            'horario' => hora_inout_prueba('09:00'), 'ingresosIds' => array_column($entries, 'Id'),
            'patentesIngresos' => [$entries[0]['Id'] => ' zz999zz ', $entries[1]['Id'] => '']];
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $plateExit)['status'] === 201, 'egreso con patentes editadas ' . $kind);
        $plateRows->execute([$plateExit['transaccionId']]);
        check_inout(array_column($plateRows->fetchAll(), 'Patente') === ['ZZ999ZZ', null, 'AA000BB'], 'egreso edita, borra y hereda patente omitida ' . $kind);
        $plateExit['patentesIngresos'][$entries[0]['Id']] = 'OTRA123';
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $plateExit)['status'] === 200, 'reintento con patente no duplica ' . $kind);
        $plateRows->execute([$plateExit['transaccionId']]);
        check_inout(array_column($plateRows->fetchAll(), 'Patente') === ['ZZ999ZZ', null, 'AA000BB'], 'reintento conserva patentes guardadas ' . $kind);
        $pdo->prepare('UPDATE InOutSeguridad SET DateAdd = ? WHERE TransaccionId = ?')->execute(['2000-04-05 10:00:00', $plateExit['transaccionId']]);
        $plateList = http_inout('/inout-seguridad?fecha=2000-04-05', $porter['token']);
        $listedPlates = array_column($plateList['body']['registros'], 'patente', 'id');
        $plateRows->execute([$plateExit['transaccionId']]); $exits = $plateRows->fetchAll();
        check_inout(($listedPlates[$exits[0]['Id']] ?? null) === 'ZZ999ZZ', 'listado devuelve patente ' . $kind);
    }
    foreach ([str_repeat('A', 16), 'AB<script>', ['AB123CD'], 123] as $invalidPlate) {
        $badPlate = $plateEntry; $badPlate['transaccionId'] = inout_uuid();
        $badPlate['personas'][1]['patente'] = $invalidPlate;
        check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $badPlate)['status'] === 422, 'rechaza patente invalida ' . json_encode($invalidPlate));
        $plateRows->execute([$badPlate['transaccionId']]);
        check_inout($plateRows->fetchAll() === [], 'patente invalida no guarda lote parcial');
    }
    $badPlate = $plateBatch; $badPlate['transaccionId'] = inout_uuid(); $badPlate['patentesLegajos'] = [999999 => 'AB123CD'];
    check_inout(http_inout('/inout-seguridad', $porter['token'], 'POST', $badPlate)['status'] === 422, 'rechaza patente para legajo no seleccionado');
    echo "TOTAL: $checks verificaciones correctas." . PHP_EOL;
} finally {
        foreach ($ids as $id) {
            $pdo->prepare('DELETE nu FROM MovilNovedadUsuario nu INNER JOIN MovilNovedad n ON n.Id = nu.NovedadId WHERE n.EnviadoPorUsuarioId = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM MovilNovedad WHERE EnviadoPorUsuarioId = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM MovilPushToken WHERE UsuarioId = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM InOutSeguridad WHERE IngresoId IS NOT NULL AND (UserAdd = ? OR UsuarioVisitaId = ?)')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM InOutSeguridad WHERE UserAdd = ? OR UsuarioVisitaId = ?')->execute([$id, $id]);
            $pdo->prepare('DELETE FROM RolUsuario WHERE UsuariosId = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM Usuarios WHERE Id = ?')->execute([$id]);
        }
        echo 'Fixtures eliminadas.' . PHP_EOL;
}
