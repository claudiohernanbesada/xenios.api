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
    $destinatarios = inout_destinatarios_pendientes($pdo, null, 120);
    $sent = 0; $pending = 0;
    foreach ($destinatarios as $destinatario) {
        try { $r = inout_send_notification($pdo, $destinatario['TransaccionId'], $destinatario['UsuarioVisitaId']); $sent += $r['sent']; if (!$r['ok']) $pending++; }
        catch (Throwable $e) { $pending++; error_log('[inout-worker] ' . $e->getMessage()); }
    }
    echo json_encode(['transacciones' => count(array_unique(array_column($destinatarios, 'TransaccionId'))), 'pushEnviados' => $sent, 'pendientes' => $pending], JSON_THROW_ON_ERROR) . PHP_EOL;
} finally { $pdo->query("SELECT RELEASE_LOCK('sgi_inout_push')"); }
