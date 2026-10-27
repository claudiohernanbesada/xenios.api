<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$basePath = __DIR__;
require_once $basePath . '/lib/db.php';
require_once $basePath . '/lib/inout_seguridad.php';

$options = getopt('', ['desde:', 'dry-run']);
$desde = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', (string) ($options['desde'] ?? ''), novedades_timezone());
if (!$desde || $desde->format('Y-m-d\TH:i:s') !== ($options['desde'] ?? '')) {
    fwrite(STDERR, "Uso: php procesar_alertas_push.php --desde=AAAA-MM-DDTHH:MM:SS [--dry-run]\n");
    exit(1);
}
$config = require $basePath . '/config.php';
$pdo = db_connect($config);
// Comparte el bloqueo con el worker anterior para evitar envíos simultáneos.
if ((int) $pdo->query("SELECT GET_LOCK('xenios_inout_push', 0)")->fetchColumn() !== 1) exit;
try {
    $cutoff = $desde->format('Y-m-d H:i:s');
    $visitRows = inout_destinatarios_pendientes($pdo, $cutoff);
    $news = $pdo->prepare('SELECT n.Id, n.Titulo, n.Cuerpo, nu.UsuarioId FROM MovilNovedad n
        INNER JOIN MovilNovedadUsuario nu ON nu.NovedadId = n.Id
        INNER JOIN Usuarios u ON u.Id = nu.UsuarioId AND u.Estado = 1
        WHERE nu.LeidaEn IS NULL AND n.CreadoEn >= ? AND n.CreadoEn <= DATE_SUB(NOW(), INTERVAL 1 MINUTE)');
    $news->execute([$cutoff]);
    $newsRows = $news->fetchAll();
    $sent = 0; $failed = 0;
    if (!array_key_exists('dry-run', $options)) {
        foreach ($visitRows as $row) {
            try { $r = inout_send_notification($pdo, $row['TransaccionId'], $row['UsuarioVisitaId']); $sent += $r['sent']; if (!$r['ok']) $failed++; }
            catch (Throwable $e) { $failed++; error_log('[recordatorio-visita] ' . $e->getMessage()); }
        }
        foreach ($newsRows as $row) {
            // Releer antes de enviar: otra app puede haber confirmado la lectura.
            $stillPending = $pdo->prepare('SELECT 1 FROM MovilNovedadUsuario WHERE NovedadId = ? AND UsuarioId = ? AND LeidaEn IS NULL');
            $stillPending->execute([$row['Id'], $row['UsuarioId']]);
            if (!$stillPending->fetchColumn()) continue;
            try {
                $messages = push_native_build_novedad_messages(novedades_tokens_por_usuarios($pdo, [$row['UsuarioId']]), $row['Id'], $row['Titulo'], $row['Cuerpo']);
                $result = push_native_send_batch($messages); $sent += $result['sent'];
                if (!$result['ok']) { $failed++; error_log('[recordatorio-novedad] ' . push_native_text_cut(implode(' | ', $result['errors']), 1000)); }
            } catch (Throwable $e) { $failed++; error_log('[recordatorio-novedad] ' . $e->getMessage()); }
        }
    }
    echo json_encode(['dryRun' => array_key_exists('dry-run', $options), 'visitas' => count($visitRows),
        'novedades' => count($newsRows), 'pushEnviados' => $sent, 'errores' => $failed], JSON_THROW_ON_ERROR), PHP_EOL;
} finally { $pdo->query("SELECT RELEASE_LOCK('xenios_inout_push')"); }
