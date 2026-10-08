<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/horas_extra_motivo_pendientes.php';

$config = require dirname(__DIR__) . '/config.php';

try {
    $pdo = db_connect($config);
    $resultado = horas_extra_motivo_pendientes_enviar($pdo, $config);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($resultado['ok'] ? 0 : 1);
} catch (Throwable $e) {
    error_log('[horas_extra_motivo] Error no controlado en cron: ' . $e->getMessage());
    echo json_encode([
        'ok' => false,
        'message' => 'Error interno al enviar notificaciones de horas extras pendientes.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
