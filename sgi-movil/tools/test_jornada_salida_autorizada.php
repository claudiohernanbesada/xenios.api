<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/horas_trabajadas_empleado.php';

$fecha = '2026-10-13';
$periodo = horas_trabajadas_normalizar_periodo(new DateTimeImmutable($fecha), new DateTimeImmutable($fecha));
$datos = ['turnos' => [
    ['Entrada'=>'06:00:00', 'Salida'=>'15:00:00'],
    ['Entrada'=>'07:00:00', 'Salida'=>'16:00:00'],
    ['Entrada'=>'15:00:00', 'Salida'=>'23:45:00'],
    ['Entrada'=>'14:00:00', 'Salida'=>'23:00:00'],
], 'feriados'=>[], 'licenciasCgs'=>[], 'vacaciones'=>[], 'ausenciasResta'=>[],
    'config'=>ht_asistencia_calculo_config([])];
$empleado = ['Id'=>'prueba', 'HorarioIngreso'=>'06:00', 'HorarioSalida'=>'15:00',
    'AreaNombre'=>'ESTAMPADO', 'TipoContratacionNombre'=>'Quincenal'];
$auth = ['Fecha'=>$fecha, 'HoraSalida'=>'14:00:00', 'FechaRegreso'=>'2026-10-14',
    'HoraRegreso'=>'01:00:00', 'FechaHoraSalidaEfectiva'=>"$fecha 14:00:27",
    'FechaHoraRegresoEfectiva'=>'2026-10-14 00:44:43', 'RequiereRecupero'=>0];

foreach ([false, true] as $sinRegresoEfectivo) {
    $a = $auth;
    if ($sinRegresoEfectivo) $a['FechaHoraRegresoEfectiva'] = null;
    $dias=[]; $e50=0.0; $e100=0.0; $comp=0.0; $feriado=0.0;
    ht_procesar_grupo_dia_fichadas($fecha, new DateTimeImmutable("$fecha 12:56:02"),
        new DateTimeImmutable("$fecha 14:00:27"), 1, $periodo, $datos, $empleado, [$a],
        $dias, $e50, $e100, $comp, $feriado, true, true);
    if (($dias[$fecha]['minutosNormales'] ?? 0) !== 540) {
        throw new RuntimeException('La visita autorizada debe completar la jornada de nueve horas.');
    }
    if ($e50 !== 60.0 || $e100 !== 0.0) {
        throw new RuntimeException('Solo genera extras el tramo de presencia anterior al turno, sin extenderlo hasta las 15.');
    }
}
echo "OK: 4 verificaciones de jornada autorizada y extras sin presencia ficticia.\n";
