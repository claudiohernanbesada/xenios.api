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

$pr = password_reset_config($config);
$smtp = $pr['smtp'] ?? [];

$status = [
    'host' => (string) ($smtp['host'] ?? ''),
    'port' => (int) ($smtp['port'] ?? 0),
    'username' => (string) ($smtp['username'] ?? ''),
    'passwordConfigured' => trim((string) ($smtp['password'] ?? '')) !== '',
    'ssl' => filter_var($smtp['ssl'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'encryption' => (string) ($smtp['encryption'] ?? ''),
    'from' => (string) ($pr['mail_from'] ?? ''),
    'displayName' => (string) ($pr['mail_from_name'] ?? ''),
];

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

if (!$status['passwordConfigured']) {
    fwrite(STDERR, "XENIOS_SMTP_PASSWORD no esta configurado.\n");
    exit(2);
}

$link = rtrim((string) $pr['public_base_url'], '/') . '/?token=prueba-smtp';
$ok = password_reset_send_mail($pr, $to, 'Prueba IT', $link);

echo $ok ? "SMTP_SEND_OK\n" : "SMTP_SEND_FAILED\n";
exit($ok ? 0 : 3);
