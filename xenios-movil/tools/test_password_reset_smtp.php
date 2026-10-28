<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI');
}

$to = strtolower(trim((string) ($argv[1] ?? '')));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Uso: php tools/test_password_reset_smtp.php email@dominio.com\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/password_reset.php';

require_once dirname(__DIR__) . '/lib/db.php';
$pdo = db_connect($config);
$user = password_reset_find_user_by_mail($pdo, $to);
if ($user === null) {
    fwrite(STDERR, "La prueba requiere el correo de un usuario activo de un hotel.\n");
    exit(2);
}
$pr = password_reset_config($config);
$pr['hotel_mail_config'] = $config;
$pr['hotel_mail_scope'] = $user;
echo json_encode(['hotelId' => $user['EmpresaTenantId'], 'sucursalId' => $user['SucursalId'],
    'mailer' => 'Configuracion de correo por hotel'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

$link = rtrim((string) $pr['public_base_url'], '/') . '/?token=prueba-smtp';
$ok = password_reset_send_mail($pr, $to, 'Prueba IT', $link);

echo $ok ? "SMTP_SEND_OK\n" : "SMTP_SEND_FAILED\n";
exit($ok ? 0 : 3);
