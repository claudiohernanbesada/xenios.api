<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/lib/registro_personal_jornadas.php';
function comprobar_jornada(bool $ok,string $caso): void {if(!$ok)throw new RuntimeException('Fallo: '.$caso);}
function marcas_jornada(array $fechas): array {return array_map(static fn(string $f): array=>['Fecha'=>new DateTimeImmutable($f),'EsCargaManual'=>str_contains($f,'00:43')],$fechas);}
$marcas=marcas_jornada(['2026-10-15 12:52:17','2026-10-16 00:43:00','2026-10-16 12:55:11','2026-10-16 23:00:00']);
$g=registro_personal_agrupar_jornadas($marcas,'06:00:00','15:00:00');
comprobar_jornada(count($g['2026-10-15'])===2,'madrugada cierra la entrada anterior');
comprobar_jornada($g['2026-10-15'][1]['EsCargaManual'],'se conserva procedencia manual');
comprobar_jornada($g['2026-10-15'][1]['Fecha']->format('Y-m-d H:i')==='2026-10-16 00:43','no se modifica la marca original');
comprobar_jornada(count($g['2026-10-16'])===2 && $g['2026-10-16'][0]['Fecha']->format('H:i')==='12:55','no se mezclan jornadas consecutivas');
$g=registro_personal_agrupar_jornadas(marcas_jornada(['2026-10-14 12:50','2026-10-14 23:45','2026-10-15 00:43']),'06:00','15:00');
comprobar_jornada(count($g['2026-10-14'])===2 && count($g['2026-10-15'])===1,'no agregar salida a jornada cerrada');
$g=registro_personal_agrupar_jornadas(marcas_jornada(['2026-10-15 18:30','2026-10-16 06:00']),'06:00','15:00');
comprobar_jornada(count($g)===2,'una nueva entrada a horario no se consume como salida');
$g=registro_personal_agrupar_jornadas(marcas_jornada(['2026-10-15 10:00','2026-10-16 00:43']),'06:00','15:00');
comprobar_jornada(count($g)===2,'respetar duracion maxima de 13 horas');
$g=registro_personal_agrupar_jornadas(marcas_jornada(['2026-10-15 12:52','2026-10-15 12:53','2026-10-16 00:43']),'06:00','15:00');
comprobar_jornada(count($g['2026-10-15'])===3,'duplicado de entrada no impide cierre');
$g=registro_personal_agrupar_jornadas(marcas_jornada(['2026-10-15 18:00','2026-10-16 06:00']),'18:00','06:00');
comprobar_jornada(count($g['2026-10-15'])===2,'conservar horario nocturno asignado');
$g=registro_personal_agrupar_jornadas(array_reverse($marcas),'06:00','15:00');
comprobar_jornada($g['2026-10-15'][1]['Fecha']->format('H:i')==='00:43','entrada desordenada se procesa cronologicamente');
echo "OK: 10 verificaciones de agrupacion de jornadas y marcas manuales.\n";
