<?php
/** Uso: php tools/ver_tokens_legajo.php 218 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Solo CLI');
}

$legajo = isset($argv[1]) ? (int) $argv[1] : 0;
if ($legajo <= 0) {
    fwrite(STDERR, "Uso: php ver_tokens_legajo.php <legajo>\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/config.php';
$db = $config['db'];
$pdo = new PDO(
    sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $db['host'], $db['name']),
    $db['user'],
    $db['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$stmt = $pdo->prepare(
    'SELECT p.Legajo, p.Nombre, p.Apellido, u.Id AS UsuarioId, u.Mail
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
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user === false) {
    echo json_encode(['error' => 'Sin usuario vinculado'], JSON_PRETTY_PRINT) . "\n";
    exit(1);
}

$tok = $pdo->prepare(
    'SELECT ExpoPushToken AS PushToken, Plataforma, ActualizadoEn
     FROM MovilPushToken
     WHERE UsuarioId = :uid'
);
$tok->execute(['uid' => $user['UsuarioId']]);
$tokens = $tok->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'legajo' => $legajo,
    'usuario' => $user,
    'tokens' => $tokens,
    'totalTokens' => count($tokens),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
