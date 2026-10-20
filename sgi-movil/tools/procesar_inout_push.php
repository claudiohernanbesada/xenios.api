<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/inout_seguridad.php';

$config = require dirname(__DIR__) . '/config.php';
$pdo = db_connect($config);
// Una sola instancia del worker puede enviar recordatorios en esta base.
if ((int) $pdo->query("SELECT GET_LOCK('sgi_inout_push', 0)")->fetchColumn() !== 1) exit;
try {
    $ids = $pdo->query('SELECT TransaccionId FROM InOutSeguridad
        WHERE Estado = 1 AND UsuarioVisitaId IS NOT NULL AND NotificacionLeidaEn IS NULL
          AND (NotificacionEnviadaEn IS NULL OR NotificacionEnviadaEn < DATE_SUB(NOW(), INTERVAL 2 MINUTE))
        GROUP BY TransaccionId ORDER BY MIN(DateAdd) LIMIT 100')->fetchAll(PDO::FETCH_COLUMN);
    $sent = 0; $pending = 0;
    foreach ($ids as $id) {
        try { $r = inout_send_notification($pdo, $id); $sent += $r['sent']; if (!$r['ok']) $pending++; }
        catch (Throwable $e) { $pending++; error_log('[inout-worker] ' . $e->getMessage()); }
    }
    echo json_encode(['transacciones' => count($ids), 'pushEnviados' => $sent, 'pendientes' => $pending], JSON_THROW_ON_ERROR) . PHP_EOL;
} finally { $pdo->query("SELECT RELEASE_LOCK('sgi_inout_push')"); }
