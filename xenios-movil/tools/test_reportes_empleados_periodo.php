<?php
declare(strict_types=1);

$base = dirname(__DIR__);
$config = require $base . '/config.php';
require_once $base . '/lib/db.php';
require_once $base . '/lib/reportes_xenios.php';
$pdo = db_connect($config);
$hotelPrueba = $pdo->query('SELECT EmpresaTenantId FROM Personal LIMIT 1')->fetchColumn();
xenios_tenant_select($pdo, (string) $hotelPrueba);
// Solo se modifica una tabla temporal de esta conexion; los datos reales no se alteran.
$pdo->exec('CREATE TEMPORARY TABLE Personal AS SELECT * FROM Personal LIMIT 1');
try {
    $pdo->exec("UPDATE Personal SET FechaAlta = '2020-01-01'");
    $desde = new DateTimeImmutable('2026-10-01');
    $hasta = new DateTimeImmutable('2026-10-15');
    $casos = [
        [2, '2026-09-30', false, 'baja anterior al periodo'],
        [2, '2026-10-01', true, 'baja el primer dia del periodo'],
        [2, '2026-10-10', true, 'baja dentro del periodo'],
        [2, '2026-10-15', true, 'baja el ultimo dia del periodo'],
        [2, '2026-10-16', true, 'baja posterior al periodo'],
        [1, null, true, 'personal activo sin baja'],
        [1, '2026-09-30', false, 'fecha de baja prevalece sobre estado activo'],
        [2, null, true, 'baja sin fecha conserva criterio existente'],
        [3, null, false, 'eliminado no integra el listado'],
    ];
    $update = $pdo->prepare('UPDATE Personal SET Estado = :estado, FechaBaja = :baja');
    foreach ($casos as [$estado, $baja, $esperado, $nombre]) {
        $update->execute(['estado'=>$estado, 'baja'=>$baja]);
        $filas = reportes_xenios_empleados($pdo, $desde, $hasta);
        if (($filas !== []) !== $esperado) throw new RuntimeException('Fallo: ' . $nombre);
    }
    $update->execute(['estado'=>2, 'baja'=>'2026-09-30']);
    if (reportes_xenios_empleados($pdo, new DateTimeImmutable('2026-09-01'), new DateTimeImmutable('2026-09-30')) === []) {
        throw new RuntimeException('La baja debe mantenerse visible en el periodo historico correspondiente.');
    }
    echo "OK: 10 verificaciones de inclusion por fecha de baja.\n";
} finally {
    $pdo->exec('DROP TEMPORARY TABLE Personal');
}
