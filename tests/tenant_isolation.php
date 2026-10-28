<?php
declare(strict_types=1);
require_once __DIR__ . '/../xenios-movil/lib/reportes_xenios.php';
require_once __DIR__ . '/../xenios-movil/lib/novedades.php';

const HOTEL_A = 'ea4a0000-0000-4000-8000-000000000001';
const HOTEL_B = '13531d3f-77c6-4fb0-b731-0b8a206b3ac0';
$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function rejected(callable $action, string $message): void {
    try { $action(); } catch (RuntimeException|InvalidArgumentException) { check(true, $message); return; }
    throw new RuntimeException($message);
}
function fixture(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $schema = [
        'Personal (Id TEXT, EmpresaTenantId TEXT, Legajo INTEGER, Nombre TEXT, Apellido TEXT, Foto TEXT, Estado INTEGER, FechaAlta TEXT, FechaBaja TEXT, HorarioIngreso TEXT, HorarioSalida TEXT, HorasExtras INTEGER, TipoContratacionId INTEGER, ArealId TEXT, DepositoId TEXT)',
        'TipoContratacion (Id INTEGER, NombreTipoContratacion TEXT)', 'Area (Id TEXT, Nombre TEXT)', 'Depositos (Id TEXT, Nombre TEXT)',
        'FichadasGlobal (EmpresaTenantId TEXT, Legajo TEXT, Fecha TEXT, Estado INTEGER, EsCargaManual INTEGER, MotivoId TEXT)',
        'FichadaGlobalManualMotivos (Id TEXT, Motivo TEXT)', 'Turnos (EmpresaTenantId TEXT, Entrada TEXT, Salida TEXT, Estado INTEGER)',
        'PersonalFeriados (EmpresaTenantId TEXT, Fecha TEXT, Descripcion TEXT, Estado INTEGER)',
        'PersonalLicencias (EmpresaTenantId TEXT, PersonalId TEXT, PersonalLicenciasMotivoId TEXT, Desde TEXT, Hasta TEXT, Estado INTEGER, Comentarios TEXT)',
        'PersonalLicenciasMotivo (Id TEXT, Codigo TEXT, Motivo TEXT, Resta INTEGER)',
        'AuthSalida (EmpresaTenantId TEXT, PersonalId TEXT, Fecha TEXT, FechaRegreso TEXT, HoraSalida TEXT, HoraRegreso TEXT, FechaHoraSalidaEfectiva TEXT, FechaHoraRegresoEfectiva TEXT, MotivoId TEXT, Estado INTEGER)',
        'AuthSalidaMotivos (Id TEXT, MotivoAuth TEXT, RequiereRecupero INTEGER)',
        'HoraExtraManual (EmpresaTenantId TEXT, PersonalId TEXT, Fecha TEXT, Desde TEXT, Hasta TEXT, MotivoId TEXT, Estado INTEGER)',
        'HoraExtraManualMotivo (Id TEXT, Motivo TEXT, Estado INTEGER)',
        'Usuarios (Id TEXT PRIMARY KEY, EmpresaTenantId TEXT, Nombre TEXT, Apellido TEXT, Mail TEXT, Estado INTEGER)',
        'Roles (Id INTEGER, RolName TEXT)', 'RolUsuario (RolesId INTEGER, UsuariosId TEXT)',
        'MovilNovedad (Id TEXT PRIMARY KEY, EmpresaTenantId TEXT NOT NULL, Titulo TEXT, Cuerpo TEXT, EnviadoPorUsuarioId TEXT, CreadoEn TEXT)',
        'MovilNovedadUsuario (NovedadId TEXT, UsuarioId TEXT, LeidaEn TEXT, EmpresaTenantId TEXT NOT NULL)',
        'MovilPushToken (UsuarioId TEXT, ExpoPushToken TEXT, Plataforma TEXT)'
    ];
    foreach ($schema as $table) $pdo->exec('CREATE TABLE ' . $table);
    foreach ([HOTEL_A => 'a', HOTEL_B => 'b'] as $hotel => $suffix) {
        $pdo->prepare("INSERT INTO Personal VALUES (?, ?, 7, ?, 'Hotel', '', 1, '2020-01-01', NULL, '08:00', '17:00', 1, 1, ?, ?)")->execute(['personal-' . $suffix, $hotel, $suffix, 'area-' . $suffix, 'sucursal-' . $suffix]);
        $pdo->prepare('INSERT INTO Area VALUES (?, ?)')->execute(['area-' . $suffix, $suffix]);
        $pdo->prepare('INSERT INTO Depositos VALUES (?, ?)')->execute(['sucursal-' . $suffix, $suffix]);
        $pdo->prepare('INSERT INTO Usuarios VALUES (?, ?, ?, ?, ?, 1)')->execute(['user-' . $suffix, $hotel, $suffix, 'Hotel', $suffix . '@test.local']);
        $pdo->prepare('INSERT INTO RolUsuario VALUES (1, ?)')->execute(['user-' . $suffix]);
        $pdo->prepare("INSERT INTO PersonalFeriados VALUES (?, ?, ?, 1)")->execute([$hotel, $suffix === 'a' ? '2026-10-12' : '2026-10-13', 'Feriado ' . $suffix]);
        $pdo->prepare('INSERT INTO Turnos VALUES (?, ?, ?, 1)')->execute([$hotel, $suffix === 'a' ? '08:00:00' : '04:00:00', '17:00:00']);
        foreach ($suffix === 'a' ? ['08:00:00', '17:00:00'] : ['04:00:00', '12:00:00'] as $hour) {
            $pdo->prepare("INSERT INTO FichadasGlobal VALUES (?, '7', ?, 1, 1, NULL)")->execute([$hotel, '2026-10-14 ' . $hour]);
        }
    }
    $pdo->exec("INSERT INTO TipoContratacion VALUES (1, 'Mensual'); INSERT INTO Roles VALUES (1, 'EMPLEADO')");
    return $pdo;
}

foreach ([HOTEL_A => 'a', HOTEL_B => 'b'] as $hotel => $suffix) {
    $pdo = fixture();
    rejected(fn() => xenios_tenant_sql($pdo), 'No debe consultar sin hotel');
    rejected(fn() => xenios_tenant_select($pdo, 'invalid'), 'Debe rechazar un identificador invalido');
    xenios_tenant_select($pdo, $hotel);
    rejected(fn() => xenios_tenant_select($pdo, $hotel === HOTEL_A ? HOTEL_B : HOTEL_A), 'No debe cambiar de hotel durante la operacion');
    $from = new DateTimeImmutable('2026-10-01'); $to = new DateTimeImmutable('2026-10-20');
    $employees = reportes_xenios_empleados($pdo, $from, $to);
    check(count($employees) === 1 && $employees[0]['Id'] === 'personal-' . $suffix, 'Listado de personal aislado');
    check(reportes_xenios_empleados($pdo, $from, $to, null, 'sucursal-' . ($suffix === 'a' ? 'b' : 'a')) === [], 'Sucursal ajena no devuelve personal');
    check(count(reportes_xenios_feriados($pdo, $from, $to)) === 1, 'Feriados aislados');
    $marks = reportes_xenios_fichadas_detalle($pdo, '7', $from, $to);
    check(count($marks[14]) === 2 && $marks[14][0]['fecha']->format('H:i') === ($suffix === 'a' ? '08:00' : '04:00'), 'Legajos repetidos no mezclan fichadas');
    $data = horas_trabajadas_cargar_datos($pdo, $employees[0], horas_trabajadas_normalizar_periodo($from, $to));
    check(count($data['fichadas']) === 2 && count($data['feriados']) === 1 && count($data['turnos']) === 1, 'Calculo de horas aislado');
    $report = reportes_xenios_asistencia($pdo, 2026, 10, null, []);
    check(count($report['employees']) === 1 && count((array) $report['feriado']) === 1, 'Reporte completo aislado');
    check(novedades_resolve_destinatarios($pdo, null) === ['user-' . $suffix], 'Todos alcanza solo al hotel del remitente');
    $other = 'user-' . ($suffix === 'a' ? 'b' : 'a');
    check(novedades_resolve_destinatarios($pdo, [$other]) === [], 'Destinatario ajeno no seleccionable');
    rejected(fn() => novedades_crear_y_enviar($pdo, 'Privado', 'Contenido', 'user-' . $suffix, [$other]), 'No debe enviar entre hoteles');
    check((int) $pdo->query('SELECT COUNT(*) FROM MovilNovedad')->fetchColumn() === 0, 'Un envio rechazado no deja registros');
    $result = novedades_crear_y_enviar($pdo, 'Privado', 'Contenido', 'user-' . $suffix, ['user-' . $suffix]);
    check($result['destinatarios'] === 1, 'Envio al hotel propio');
    check($pdo->query('SELECT EmpresaTenantId FROM MovilNovedad')->fetchColumn() === $hotel, 'Novedad guarda el hotel real');
    check($pdo->query('SELECT EmpresaTenantId FROM MovilNovedadUsuario')->fetchColumn() === $hotel, 'Relacion guarda el hotel real');
}
echo "OK: $checks verificaciones de aislamiento entre dos hoteles.\n";
