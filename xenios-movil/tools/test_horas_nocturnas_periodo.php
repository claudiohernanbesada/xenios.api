<?php
declare(strict_types=1);
$b=dirname(__DIR__);
$config=require $b.'/config.php';
require_once $b.'/lib/db.php';
require_once $b.'/lib/reportes_xenios.php';
function verificar_nocturno(bool $ok,string $caso): void {
    if(!$ok) throw new RuntimeException('Fallo: '.$caso);
}
$desde=new DateTimeImmutable('2026-10-01'); $hasta=new DateTimeImmutable('2026-10-15'); $hoy=new DateTimeImmutable('2026-10-20');
$cerrada=[['Fecha'=>new DateTimeImmutable('2026-10-15 17:30')],['Fecha'=>new DateTimeImmutable('2026-10-16 06:00')]];
$incompleta=static fn(array $f,array $lic=[]): bool=>ht_tiene_fichada_incompleta($f,[],[],$desde,$hasta,$hoy,'prueba','VIGILANCIA','18:00','06:00',[],$lic);
verificar_nocturno(!$incompleta($cerrada),'salida del dia siguiente cierra el ultimo turno');
verificar_nocturno(!$incompleta([...$cerrada,['Fecha'=>new DateTimeImmutable('2026-10-19 17:30')]]),'N/I fuera de periodo no invalida el periodo');
verificar_nocturno(!$incompleta([...$cerrada,['Fecha'=>new DateTimeImmutable('2026-10-16 17:30')]]),'turno posterior al corte no invalida el periodo');
verificar_nocturno($incompleta([$cerrada[0]]),'entrada sin salida dentro del periodo conserva la alerta');
verificar_nocturno(!$incompleta([['Fecha'=>new DateTimeImmutable('2026-10-01 06:00')]]),'salida de turno anterior no requiere entrada dentro del periodo');
verificar_nocturno(!$incompleta([$cerrada[0]],[['Desde'=>'2026-10-15','Hasta'=>'2026-10-15']]),'licencia justificada no provoca alerta');
$pdo=db_connect($config);
$empleado=$pdo->query('SELECT Id,Legajo FROM Personal WHERE Legajo=221')->fetch();
$empleado=horas_trabajadas_enriquecer_personal($pdo,$empleado);
$plantilla=$pdo->query('SELECT * FROM FichadasGlobal LIMIT 1')->fetch();
// Tabla temporal: no se modifica ninguna fichada real.
$pdo->exec('CREATE TEMPORARY TABLE FichadasGlobal AS SELECT * FROM FichadasGlobal WHERE 1=0');
try {
    $campos=array_keys($plantilla);
    $insert=$pdo->prepare('INSERT INTO FichadasGlobal (`'.implode('`,`',$campos).'`) VALUES ('.implode(',',array_fill(0,count($campos),'?')).')');
    foreach(['2026-10-01 06:00:00','2026-10-15 17:30:00','2026-10-16 06:00:00','2026-10-16 17:30:00','2026-10-19 17:30:00']as$fecha){
        $row=$plantilla; foreach($campos as $campo) {
            if(strcasecmp($campo,'Fecha')===0) $row[$campo]=$fecha;
            if(strcasecmp($campo,'Legajo')===0) $row[$campo]='0221';
            if(strcasecmp($campo,'Estado')===0) $row[$campo]=1;
        }
        $insert->execute(array_values($row));
    }
    $per=horas_trabajadas_normalizar_periodo($desde,$hasta);
    $datos=horas_trabajadas_cargar_datos($pdo,$empleado,$per);$datos['config']=ht_asistencia_calculo_config($config);
    verificar_nocturno(count($datos['fichadas'])===2,'carga incluye solo el turno iniciado dentro del periodo');
    verificar_nocturno($datos['fichadas'][1]['Fecha']->format('Y-m-d')==='2026-10-16','carga conserva la salida del ultimo turno');
    $r=horas_trabajadas_procesar_empleado($per,$datos,$empleado);
    verificar_nocturno(!$r['tieneFichadaIncompleta'] && $r['minutosTrabajados']===720,'jornada nocturna valida genera 12 horas con licencias en el periodo');
} finally { $pdo->exec('DROP TEMPORARY TABLE FichadasGlobal'); }
echo "OK: 9 verificaciones de corte de periodo, nocturnos y licencias.\n";
