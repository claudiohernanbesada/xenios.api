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
$news = push_native_build_novedad_messages([['token' => str_repeat('c', 32), 'plataforma' => 'android']], 'novedad-test', 'Aviso importante', str_repeat('á', 3000));
$newsPayload = push_fcm_build_payload($news[0])['message'];
check_push_visita($newsPayload['android']['notification']['channel_id'] === 'novedades-v2' && $newsPayload['android']['notification']['sound'] === 'campana', 'novedades usa canal sonoro propio');
check_push_visita($newsPayload['android']['priority'] === 'HIGH' && $newsPayload['android']['notification']['notification_priority'] === 'PRIORITY_MAX'
    && count($newsPayload['android']['notification']['vibrate_timings']) === 6, 'novedad urgente con sonido y vibración en segundo plano');
check_push_visita($newsPayload['data']['tipo'] === 'novedad' && $newsPayload['data']['novedadId'] === 'novedad-test', 'push de novedad identifica contenido sin marcar lectura');
check_push_visita(strlen(json_encode($newsPayload, JSON_UNESCAPED_UNICODE)) < 4096 && str_contains($newsPayload['notification']['body'], 'Ver contenido completo'), 'novedad extensa mantiene payload por debajo de 4 KB');
check_push_visita(novedades_normalizar_plataforma('android:ring-v1') === 'android:ring-v1'
    && novedades_normalizar_plataforma('android:otro') === 'android', 'conserva solo la capacidad nativa reconocida');
check_push_visita(push_native_token_valido(str_repeat('a', 32), 'android:ring-v1'), 'acepta token FCM de binario con alerta insistente');
foreach ([$messages[0], $news[0]] as $message) {
    $message['plataforma'] = 'android:ring-v1';
    $native = push_fcm_build_payload($message)['message'];
    $content = json_decode($native['data']['body'], true, 512, JSON_THROW_ON_ERROR);
    check_push_visita(!isset($native['notification']) && !isset($native['android']['notification'])
        && $native['data']['title'] === $message['title'] && $native['data']['message'] === $message['body'],
        'binario nativo presenta su alerta sin que Google saltee el receptor');
    check_push_visita($native['android']['priority'] === 'HIGH' && $content === $message['data']
        && $native['data']['channelId'] === $message['channelId'], 'mantiene prioridad, canal y datos de navegación y lectura');
    check_push_visita(str_starts_with($native['data']['tag'], $content['tipo'] === 'novedad' ? 'novedad-' : 'visita-')
        && $native['data']['autoDismiss'] === 'true', 'identificador estable y cancelación al abrir el aviso');
    check_push_visita(strlen(json_encode($native, JSON_UNESCAPED_UNICODE)) < 4096, 'payload nativo extenso menor a 4 KB');
}
$fichada = push_native_build_fichada_messages([['token' => str_repeat('a', 32), 'plataforma' => 'android:ring-v1', 'fecha' => '21/10/2026', 'hora' => '12:00']])[0];
check_push_visita(isset(push_fcm_build_payload($fichada)['message']['notification']), 'fichadas conserva aviso normal en el nuevo binario');
echo 'TOTAL: ' . $checks . ' verificaciones correctas.' . PHP_EOL;
