<?php
declare(strict_types=1);
require_once __DIR__ . '/../xenios-movil/lib/auth_salida_daily_report.php';

$checks = 0;
function check_mail(bool $condition, string $label): void {
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}
$a = 'ea4a0000-0000-4000-8000-000000000001';
$b = '22222222-2222-4222-8222-222222222222';
$ua = '11111111-1111-4111-8111-111111111111';
$ub = '33333333-3333-4333-8333-333333333333';
$branch = '44444444-4444-4444-8444-444444444444';
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ([
    'Hoteles (Id TEXT, Activa INT)',
    'Usuarios (Id TEXT, Nombre TEXT, Apellido TEXT, Mail TEXT, Estado INT, EmpresaTenantId TEXT, DepositoId TEXT)',
    'Roles (Id INT, RolName TEXT)', 'RolUsuario (UsuariosId TEXT, RolesId INT)',
    'Personal (Id TEXT, Legajo INT, Nombre TEXT, Apellido TEXT, EmpresaTenantId TEXT)',
    'AuthSalida (Id TEXT, PersonalId TEXT, MotivoId INT, Estado INT, Fecha TEXT, HoraSalida TEXT, FechaRegreso TEXT, HoraRegreso TEXT, FechaHoraSalidaEfectiva TEXT, FechaHoraRegresoEfectiva TEXT, MotivoDetalle TEXT, EmpresaTenantId TEXT)',
    'AuthSalidaMotivos (Id INT, MotivoAuth TEXT, EmpresaTenantId TEXT)',
    'PersonalLicencias (PersonalId TEXT, PersonalLicenciasMotivoId INT, Estado INT, Desde TEXT, Hasta TEXT, Comentarios TEXT, EmpresaTenantId TEXT)',
    'PersonalLicenciasMotivo (Id INT, Motivo TEXT, EmpresaTenantId TEXT)',
] as $schema) $pdo->exec('CREATE TABLE ' . $schema);
$pdo->exec("INSERT INTO Hoteles VALUES ('$a',1),('$b',1)");
$pdo->exec("INSERT INTO Usuarios VALUES ('$ua','Usuario','A','a@example.test',1,'$a',NULL),('$ub','Usuario','B','b@example.test',1,'$b','$branch')");
$pdo->exec("INSERT INTO Roles VALUES (1,'RRHH_REPORTE_MAIL_AUTH')");
$pdo->exec("INSERT INTO RolUsuario VALUES ('$ua',1),('$ub',1)");
$pdo->exec("INSERT INTO Personal VALUES ('pa',1,'Personal','A','$a'),('pb',2,'Personal','B','$b')");
$pdo->exec("INSERT INTO AuthSalidaMotivos VALUES (1,'Personal','$a'),(2,'Personal','$b')");
$pdo->exec("INSERT INTO PersonalLicenciasMotivo VALUES (1,'Licencia','$a'),(2,'Licencia','$b')");
foreach ([['pa',$a],['pb',$b]] as [$person,$hotel]) {
    $stmt = $pdo->prepare('INSERT INTO AuthSalida VALUES (?, ?, ?, 1, ?, ?, ?, ?, NULL, NULL, ?, ?)');
    $stmt->execute([$person,$person,$hotel === $a ? 1 : 2,'2026-10-28','10:00','2026-10-28','12:00','Test',$hotel]);
    $pdo->prepare('INSERT INTO PersonalLicencias VALUES (?,?,1,?,?,?,?)')->execute([$person,$hotel === $a ? 1 : 2,'2026-10-28','2026-10-28','Test',$hotel]);
}
$user = password_reset_find_user_by_mail($pdo, ' B@EXAMPLE.TEST ');
check_mail($user['EmpresaTenantId'] === $b && $user['SucursalId'] === $branch, 'Recupero identifica el hotel y sucursal del usuario');
$payload = tenant_mail_payload($user, [$user['Mail']], 'Recupero', '<p>Test</p>');
check_mail($payload['empresaId'] === $b && $payload['sucursalId'] === $branch && $payload['usuarioId'] === $ub, 'Puente conserva el ámbito');
check_mail(!array_key_exists('password', $payload) && !array_key_exists('smtp', $payload), 'No se transfieren credenciales SMTP');
check_mail(password_reset_find_user_by_mail($pdo, 'missing@example.test') === null, 'Cuenta inexistente');
$recipients = auth_salida_daily_report_recipients($pdo);
check_mail(count($recipients) === 2, 'Destinatarios tipificados');
check_mail(isset($recipients[0]['Id']) && isset($recipients[1]['Id']), 'El puente verifica la cuenta de cada destinatario');
foreach ([$a,$b] as $hotel) {
    $date = new DateTimeImmutable('2026-10-28');
    $rows = auth_salida_daily_report_rows($pdo,$date,$hotel);
    $licenses = auth_salida_daily_report_license_rows($pdo,$date,$hotel);
    check_mail(count($rows) === 1 && count($licenses) === 1, 'Consulta por hotel');
    check_mail($rows[0]['empleado'] === ($hotel === $a ? 'A, Personal' : 'B, Personal'), 'No mezcla empleados entre hoteles');
}
$pdo->exec("UPDATE Hoteles SET Activa=0 WHERE Id='$b'");
check_mail(password_reset_find_user_by_mail($pdo, 'b@example.test') === null, 'Hotel inactivo no recupera cuenta');
check_mail(count(auth_salida_daily_report_recipients($pdo)) === 1, 'Hotel inactivo no recibe reportes');
try { tenant_mail_payload([], ['a@example.test'], 'Test', 'Test'); throw new RuntimeException('Se aceptó hotel vacío'); }
catch (InvalidArgumentException) { $checks++; }
echo json_encode(['checks' => $checks, 'passed' => true], JSON_UNESCAPED_UNICODE) . PHP_EOL;
