<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/inout_seguridad.php';
$checks = 0;
function check_push_visita(bool $ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException('Fallo: ' . $name);
    $checks++; echo 'OK ' . $name . PHP_EOL;
}
// Pruebas de contenido sin red, sin credenciales y sin enviar alertas a personas.
$note = ['transaccionId' => inout_uuid(), 'title' => 'Ingreso de Invitado', 'body' => "Empresa: Prueba\n\nEsta ingresando a la empresa:\n- Ana Prueba"];
$messages = inout_build_push_messages([
    ['token' => str_repeat('a', 32), 'plataforma' => 'android'],
    ['token' => str_repeat('b', 64), 'plataforma' => 'ios-production'],
    ['token' => 'ExponentPushToken[antiguo]', 'plataforma' => 'android'],
], $note);
check_push_visita(count($messages) === 2, 'solo tokens nativos válidos');
$p = push_fcm_build_payload($messages[0])['message'];
check_push_visita($p['notification']['body'] === $note['body'] && $p['notification']['title'] === $note['title'], 'alerta visible para sistema con app cerrada, no solo datos');
check_push_visita($p['android']['priority'] === 'HIGH', 'entrega urgente FCM');
$n = $p['android']['notification'];
check_push_visita($n['channel_id'] === 'visitas-v1' && $n['notification_priority'] === 'PRIORITY_MAX', 'canal de visitas y prioridad visual máxima');
check_push_visita($n['sound'] === 'campana', 'sonido Android usa nombre de recurso sin extensión');
check_push_visita($n['visibility'] === 'PUBLIC', 'presentación en pantalla bloqueada');
check_push_visita($n['vibrate_timings'] === ['0.000s', '0.500s', '0.300s', '0.500s', '0.300s', '0.700s'], 'patrón de vibración en formato FCM');
check_push_visita($p['data']['transaccionId'] === $note['transaccionId'] && $p['data']['tipo'] === 'inoutSeguridad', 'vínculo a la visita pendiente');
check_push_visita($messages[1]['sound'] === 'campana.wav', 'APNs mantiene extensión de sonido');
$default = push_fcm_build_payload(['token' => str_repeat('a', 32), 'title' => 'Sistema', 'body' => 'Prueba'])['message']['android']['notification'];
check_push_visita($default['sound'] === 'default' && !isset($default['vibrate_timings']), 'otras notificaciones conservan comportamiento por defecto');
$long = $note; $long['body'] = str_repeat('á', 1800);
$truncated = inout_build_push_messages([['token' => str_repeat('a', 32), 'plataforma' => 'android']], $long)[0]['body'];
check_push_visita(strlen($truncated) < 2400 && preg_match('//u', $truncated) === 1 && str_contains($truncated, 'Ver listado completo'), 'payload acotado sin cortar caracteres UTF-8');
echo 'TOTAL: ' . $checks . ' verificaciones correctas.' . PHP_EOL;
