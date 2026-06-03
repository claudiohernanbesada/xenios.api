<?php
/**
 * Uso (CLI): php tools/enviar_novedad_legajo.php 218 "Título" "Cuerpo"
 * Envía novedad + push al usuario vinculado al legajo (mail = Personal.Email/EmailCorporativo).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI');
}

$legajo = isset($argv[1]) ? (int) $argv[1] : 0;
$titulo = trim((string) ($argv[2] ?? ''));
$cuerpo = trim((string) ($argv[3] ?? ''));

if ($legajo <= 0 || $titulo === '' || $cuerpo === '') {
    fwrite(STDERR, "Uso: php enviar_novedad_legajo.php <legajo> \"<titulo>\" \"<cuerpo>\"\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/novedades.php';

$db = $config['db'];
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    $db['host'],
    $db['name'],
    $db['charset']
);
$pdo = new PDO($dsn, $db['user'], $db['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$stmt = $pdo->prepare(
    'SELECT p.Legajo, p.Nombre, p.Apellido, p.Email, p.EmailCorporativo, u.Id AS UsuarioId, u.Mail
     FROM Personal p
     INNER JOIN Usuarios u ON u.Estado = 1
       AND (
         LOWER(TRIM(u.Mail)) = LOWER(TRIM(p.EmailCorporativo))
         OR LOWER(TRIM(u.Mail)) = LOWER(TRIM(p.Email))
       )
     WHERE p.Legajo = :legajo
     LIMIT 1'
);
$stmt->execute(['legajo' => $legajo]);
$row = $stmt->fetch();

if ($row === false) {
    fwrite(STDERR, "No hay usuario activo vinculado al legajo {$legajo}.\n");
    exit(1);
}

$usuarioId = (string) $row['UsuarioId'];
$from = '00000000-0000-0000-0000-000000000002'; // No Reply

if (novedades_usuario_activo($pdo, $from) === null) {
    fwrite(STDERR, "Usuario remitente sistema (No Reply) no encontrado.\n");
    exit(1);
}

$missing = novedades_missing_tables($pdo);
if ($missing !== []) {
    fwrite(STDERR, 'Faltan tablas: ' . implode(', ', $missing) . "\n");
    exit(1);
}

$result = novedades_crear_y_enviar($pdo, $titulo, $cuerpo, $from, [$usuarioId]);

$out = [
    'ok' => true,
    'legajo' => $legajo,
    'destinatario' => trim($row['Nombre'] . ' ' . $row['Apellido']),
    'usuarioId' => $usuarioId,
    'mail' => $row['Mail'],
] + $result;

if (($result['pushEnviados'] ?? 0) === 0) {
    $out['aviso'] =
        'Novedad guardada, pero pushEnviados=0: el usuario no tiene token Expo en MovilPushToken '
        . '(abrir la app APK, iniciar sesión y aceptar notificaciones).';
    fwrite(STDERR, $out['aviso'] . "\n");
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
