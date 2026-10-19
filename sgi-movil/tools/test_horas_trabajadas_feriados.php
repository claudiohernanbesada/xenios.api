<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/horas_trabajadas_empleado.php';

function verificar_feriados(bool $condicion, string $nombre): void
{
    if (!$condicion) {
        throw new RuntimeException('Fallo: ' . $nombre);
    }
}

$desde = new DateTimeImmutable('2026-10-01');
$hasta = new DateTimeImmutable('2026-10-15');
$feriados = ['2026-10-12' => true];
$dias = [];
for ($dia = $desde; $dia <= $hasta; $dia = $dia->modify('+1 day')) {
    if ((int) $dia->format('N') <= 5) {
        $dias[$dia->format('Y-m-d')] = true;
    }
}
$dias['2026-10-12'] = 'feriado_no_trabajado';
$sumar = static fn(array $d, ?string $area = null): int => ht_sumar_minutos_nominales(
    $d, $feriados, $desde, $hasta, true, 'prueba', [], [], [], $area
);
$normales = $sumar($dias);
$feriado = ht_sumar_minutos_feriados_no_trabajados($dias, $feriados);
verificar_feriados($normales === 88 * 60, 'horas normales sin feriados');
verificar_feriados($feriado === 9 * 60, 'feriado se informa separado');
$resumen = horas_trabajadas_armar_respuesta(['Legajo' => 52], $normales, 10, 0, 0, 0, 0, 0, 0, $feriado, 0, 0, false);
verificar_feriados($resumen['totalAPagarFormateado'] === '88:00', 'total sin feriados');
verificar_feriados($resumen['horasFeriadosFormateadas'] === '9 hs.', 'columna feriados');
verificar_feriados($sumar(['2026-10-12' => 'feriado_no_trabajado']) === 0, 'sin fichadas no se inventan horas normales');
verificar_feriados($sumar(['2026-10-12' => 'feriado_quincenal_trabajado']) === 0, 'feriado trabajado se mantiene fuera de normales');
verificar_feriados($sumar(['2026-10-12' => true]) === 0, 'calendario de feriados prevalece sobre marca generica');
verificar_feriados($sumar(['2026-10-13' => true, '2026-10-12' => 'feriado_no_trabajado'], 'VIGILANCIA') === 12 * 60,
    'vigilancia conserva solo la jornada normal');
$resumen = horas_trabajadas_armar_respuesta(['Legajo' => 52], $normales, 10, 60, 0, 0, 0, 0, 0, $feriado, 0, 0, false);
verificar_feriados($resumen['totalAPagarFormateado'] === '87:00', 'autorizaciones descuentan sobre horas normales');
echo "OK: 9 verificaciones de horas normales, feriados y total.\n";
