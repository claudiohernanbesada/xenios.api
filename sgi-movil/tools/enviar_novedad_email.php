<?php
/**
 * Uso (CLI): php tools/enviar_novedad_email.php email@dominio.com "Título" "Cuerpo"
 * Envía novedad + push al usuario activo con ese mail (Usuarios.Mail).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI');
}

$email = strtolower(trim((string) ($argv[1] ?? '')));
$titulo = trim((string) ($argv[2] ?? ''));
$cuerpo = trim((string) ($argv[3] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $titulo === '' || $cuerpo === '') {
    fwrite(STDERR, "Uso: php enviar_novedad_email.php <email> \"<titulo>\" \"<cuerpo>\"\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/novedades.php';

$db = $config['db'];
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=%s', $db['host'], $db['name'], $db['charset']),
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$stmt = $pdo->prepare(
    'SELECT Id AS UsuarioId, Nombre, Apellido, Mail
     FROM Usuarios
     WHERE Estado = 1 AND LOWER(TRIM(Mail)) = :mail
     LIMIT 1'
);
$stmt->execute(['mail' => $email]);
$row = $stmt->fetch();

if ($row === false) {
    fwrite(STDERR, "No hay usuario activo con mail {$email}.\n");
    exit(1);
}

$usuarioId = (string) $row['UsuarioId'];
$from = '00000000-0000-0000-0000-000000000002';

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
