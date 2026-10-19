<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/horas_extra_motivo_pendientes.php';
function comprobar_calculo(bool $ok, string $caso): void {
    if (!$ok) throw new RuntimeException('Fallo: ' . $caso);
}
$casos = [
    ['06:00:00','15:00:00',540], ['06:09:00','15:00:00',540],
    ['06:09:01','15:00:00',480], ['06:10:00','15:00:00',480],
    ['06:00:00','14:51:00',540], ['06:00:00','14:50:59',480],
    ['06:09:00','14:51:00',540], ['06:10:00','14:50:00',420],
    ['07:01:00','15:00:00',420],
];
$fecha = '2026-10-14';
$datos = ['turnos'=>[['Entrada'=>'06:00:00','Salida'=>'15:00:00','Denominacion'=>'Manana']],
    'feriados'=>[], 'licenciasCgs'=>[], 'vacaciones'=>[], 'ausenciasResta'=>[],
    'config'=>ht_asistencia_calculo_config([]), 'extrasManuales'=>[], 'authPorPersonal'=>[]];
$empleado=['Id'=>'prueba','Legajo'=>52,'HorarioIngreso'=>'06:00','HorarioSalida'=>'15:00',
    'AreaNombre'=>'MATRICERIA','TipoContratacionNombre'=>'Quincenal','HorasExtras'=>true];
$periodo=horas_trabajadas_normalizar_periodo(new DateTimeImmutable($fecha),new DateTimeImmutable($fecha));
foreach ($casos as [$entrada,$salida,$esperado]) {
    $dias=[]; $e50=0.0; $e100=0.0; $comp=0.0; $feriado=0.0;
    ht_procesar_grupo_dia_fichadas($fecha,new DateTimeImmutable("$fecha $entrada"),new DateTimeImmutable("$fecha $salida"),
        2,$periodo,$datos,$empleado,[],$dias,$e50,$e100,$comp,$feriado,false,true);
    $m=ht_sumar_minutos_nominales($dias,[],$periodo['desde'],$periodo['hasta'],true,'prueba',[],[],[]);
    comprobar_calculo($m===$esperado,"tolerancia $entrada / $salida: $m");
}
$r=horas_trabajadas_armar_respuesta($empleado,5280,10,120,60,240,180,1,480,540,0,0,false);
comprobar_calculo($r['minutosTotalAPagar'] === 5700,'formula total 88 - 2 + 1 + 8 = 95');
comprobar_calculo($r['totalAPagarFormateado'] === '95:00','formato total sin extras ni feriados');
$datos['fichadas']=[['Fecha'=>new DateTimeImmutable('2026-10-14 06:00')],['Fecha'=>new DateTimeImmutable('2026-10-14 16:00')]];
$datos['extrasManuales']['prueba']=[['Fecha'=>$fecha,'Desde'=>'17:00:00','Hasta'=>'18:00:00','Motivo'=>'Prueba','MotivoEstado'=>1]];
$filas=horas_extra_motivo_diarias_empleado($periodo,$datos,$empleado,'area-prueba');
comprobar_calculo(count($filas)===1,'unica fila por empleado y fecha');
$f=$filas[0];
comprobar_calculo($f['minutosExtra50']===120 && $f['minutosFichadas50']===60,'extras automaticas y manuales sin duplicar');
comprobar_calculo(count($f['cargasManuales'])===1 && $f['cargasManuales'][0]['minutosExtra50']===60,'manual calculada por API');
echo "OK: 14 verificaciones de tolerancia, formula y extras diarias.\n";
