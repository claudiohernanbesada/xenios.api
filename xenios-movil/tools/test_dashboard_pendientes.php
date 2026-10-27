<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/notas_traslado.php';
$checks = 0;
function check_pending(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException($label);
    $checks++;
}
$row = array_fill_keys(['Id','NumeroNota','Fecha','DestinoId','UserAdd','UserRecibeId','PersonalRecibeId','DocumentoFirmado','UsuarioAprobacionId','FechaAprobacion','NombreEnvia','NombreRecibe','TransportistaAsignadoId','FechaAsignacionTransporte','TransporteExternoEmpresaId','TransporteExternoEmpresaNombre','TransporteExternoNombre','TransporteExternoApellido','TransporteExternoDni'], null);
$row += ['TipoEnvio'=>'interno','UserAddDepositoId'=>'origen','SeguridadChequeada'=>0];
$row['Fecha'] = '2020-01-01'; $row['DestinoId'] = 'destino'; $row['UserRecibeId'] = 'receptor';
$origin = ['roles'=>['NOTA_TRASLADO_DESPACHO'],'usuarioId'=>'operador','depositoId'=>'origen'];
$dest = array_replace($origin,['depositoId'=>'destino']);
$security = array_replace($origin,['roles'=>['NOTA_TRASLADO_SEGURIDAD']]);
$row['StatusNotaTrasladoId']=NT_STATUS_GENERADA;
check_pending(notas_traslado_tiene_tarea_pendiente($row,$origin),'origen recibe nota generada antigua');
check_pending(!notas_traslado_tiene_tarea_pendiente($row,$dest),'otra sede no cuenta recepcion de origen');
$row['StatusNotaTrasladoId']=NT_STATUS_EN_TRANSITO;
check_pending(notas_traslado_tiene_tarea_pendiente($row,$security),'seguridad cuenta chequeo pendiente');
$row['SeguridadChequeada']=1;
check_pending(!notas_traslado_tiene_tarea_pendiente($row,$security),'chequeo realizado por otro operador descuenta pendiente');
check_pending(notas_traslado_tiene_tarea_pendiente($row,$dest),'despacho destino cuenta recepcion');
check_pending(!notas_traslado_tiene_tarea_pendiente($row,$origin),'origen no cuenta recepcion de destino');
$row['StatusNotaTrasladoId']=NT_STATUS_RECEPCION_SEDE;
check_pending(notas_traslado_tiene_tarea_pendiente($row,$dest),'despacho destino cuenta entrega');
$row['StatusNotaTrasladoId']=NT_STATUS_ENTREGADO;
$recipient=['roles'=>[],'usuarioId'=>'receptor'];
check_pending(notas_traslado_tiene_tarea_pendiente($row,$recipient),'receptor cuenta confirmacion final');
check_pending(!notas_traslado_tiene_tarea_pendiente($row,$dest),'entrega interna completada descuenta despacho');
foreach ([NT_STATUS_RECIBIDA,NT_STATUS_ANULADA,NT_STATUS_RECHAZADA] as $state) {
 $row['StatusNotaTrasladoId']=$state;
 check_pending(!notas_traslado_tiene_tarea_pendiente($row,['roles'=>['ADMIN']]),'estado terminado no genera tareas '.$state);
}
$row['StatusNotaTrasladoId']=NT_STATUS_PENDIENTE_APROBACION;
check_pending(notas_traslado_tiene_tarea_pendiente($row,['roles'=>['NOTA_TRASLADO_APROBACION']]),'aprobador cuenta decision pendiente');
check_pending(!notas_traslado_tiene_tarea_pendiente($row,$origin),'despacho no cuenta aprobacion ajena');
$row['TipoEnvio']='externo';$row['StatusNotaTrasladoId']=NT_STATUS_GENERADA;
check_pending(!notas_traslado_tiene_tarea_pendiente($row,$origin),'bloqueado sin autorizacion no genera tarea ejecutable');
$row['UsuarioAprobacionId']='aprobador';$row['FechaAprobacion']='2026-10-22';
$row['StatusNotaTrasladoId']=NT_STATUS_ENTREGADO;
check_pending(notas_traslado_tiene_tarea_pendiente($row,$origin),'comprobante firmado pendiente');
$row['DocumentoFirmado']='archivo.pdf';
check_pending(!notas_traslado_tiene_tarea_pendiente($row,$origin),'comprobante incorporado descuenta tarea');
echo "TOTAL: $checks verificaciones correctas.\n";
