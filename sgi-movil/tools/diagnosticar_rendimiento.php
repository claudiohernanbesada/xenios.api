<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI');
}

$email = strtolower(trim((string) ($argv[1] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php diagnosticar_rendimiento.php <email>\n");
    exit(1);
}

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/asistencia_calendario.php';
require_once dirname(__DIR__) . '/lib/horas_trabajadas_empleado.php';
require_once dirname(__DIR__) . '/lib/novedades.php';
require_once dirname(__DIR__) . '/lib/vacaciones.php';
require_once dirname(__DIR__) . '/lib/auth_salida_list.php';

$config = require dirname(__DIR__) . '/config.php';
$timings = [];

/** @return mixed */
function medir_bloque(array &$timings, string $nombre, callable $callback)
{
    $inicio = hrtime(true);
    try {
        $resultado = $callback();
        $timings[$nombre] = [
            'ms' => round((hrtime(true) - $inicio) / 1_000_000, 2),
            'ok' => true,
        ];
        return $resultado;
    } catch (Throwable $e) {
        $timings[$nombre] = [
            'ms' => round((hrtime(true) - $inicio) / 1_000_000, 2),
            'ok' => false,
            'error' => $e->getMessage(),
        ];
        return null;
    }
}

$pdo = medir_bloque($timings, 'conexionDb', static fn () => db_connect($config));
if (!$pdo instanceof PDO) {
    echo json_encode(['ok' => false, 'timings' => $timings], JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

$usuario = medir_bloque($timings, 'buscarUsuario', static function () use ($pdo, $email): ?array {
    $stmt = $pdo->prepare(
        'SELECT Id, Nombre, Apellido, Mail
         FROM Usuarios
         WHERE Estado = 1 AND LOWER(TRIM(Mail)) = :mail
         LIMIT 1'
    );
    $stmt->execute(['mail' => $email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
});

if (!is_array($usuario)) {
    echo json_encode([
        'ok' => false,
        'message' => 'Usuario activo no encontrado',
        'timings' => $timings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

medir_bloque(
    $timings,
    'roles',
    static fn () => fetch_user_roles($pdo, (string) $usuario['Id'])
);

$personal = medir_bloque(
    $timings,
    'buscarPersonal',
    static fn () => fetch_personal_empleado_completo($pdo, (string) $usuario['Mail'])
);

if (!is_array($personal)) {
    echo json_encode([
        'ok' => false,
        'message' => 'Personal asociado no encontrado',
        'timings' => $timings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(1);
}

$hoy = new DateTimeImmutable('today');
$desde = $hoy->modify('first day of this month')->format('Y-m-d');
$hasta = $hoy->format('Y-m-d');
$legajo = (int) ($personal['Legajo'] ?? 0);
$claves = normalizar_legajo_claves($legajo);

medir_bloque($timings, 'fichadasHoyConsultaActual', static function () use ($pdo, $hoy, $claves): int {
    $placeholders = implode(',', array_fill(0, count($claves), '?'));
    $stmt = $pdo->prepare(
        "SELECT Id, Fecha, Sede, Controlador, EsCargaManual
         FROM FichadasGlobal
         WHERE Estado = 1
           AND DATE(Fecha) = ?
           AND Legajo IN ($placeholders)
         ORDER BY Fecha ASC"
    );
    $stmt->execute(array_merge([$hoy->format('Y-m-d')], $claves));
    return count($stmt->fetchAll(PDO::FETCH_ASSOC));
});

medir_bloque(
    $timings,
    'novedades',
    static fn () => novedades_listar_usuario($pdo, (string) $usuario['Id'])
);
medir_bloque(
    $timings,
    'autorizaciones',
    static fn () => auth_salida_list_for_personal($pdo, $personal)
);
medir_bloque(
    $timings,
    'vacaciones',
    static fn () => vacaciones_build_response($pdo, $personal, (int) $hoy->format('Y'))
);
medir_bloque(
    $timings,
    'asistenciaCalendario',
    static fn () => asistencia_build_calendario($pdo, $personal, $desde, $hasta)
);
medir_bloque(
    $timings,
    'asistenciaResumen',
    static fn () => horas_trabajadas_build_resumen($pdo, $personal, $desde, $hasta)
);

$totalMs = round(array_sum(array_column($timings, 'ms')), 2);
echo json_encode([
    'ok' => !in_array(false, array_column($timings, 'ok'), true),
    'periodo' => ['desde' => $desde, 'hasta' => $hasta],
    'timings' => $timings,
    'totalSecuencialMs' => $totalMs,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
