<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo CLI');
}

$dateArg = null;
$dryRun = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (str_starts_with($arg, '--date=')) {
        $dateArg = substr($arg, 7);
        continue;
    }
}

$tz = new DateTimeZone('America/Argentina/Buenos_Aires');
$date = $dateArg !== null && trim($dateArg) !== ''
    ? DateTimeImmutable::createFromFormat('Y-m-d', $dateArg, $tz)
    : new DateTimeImmutable('today', $tz);

if (!$date) {
    fwrite(STDERR, "Fecha invalida. Uso: php tools/enviar_auth_salida_diario.php [--date=YYYY-MM-DD] [--dry-run]\n");
    exit(1);
}

$lockPath = dirname(__DIR__) . '/logs/auth_salida_daily_report.lock';
$lockDir = dirname($lockPath);
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0755, true);
}

$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Ya hay un envio de reporte AuthSalida en ejecucion.\n");
    exit(2);
}

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth_salida_daily_report.php';

$pdo = db_connect($config);
$rows = auth_salida_daily_report_rows($pdo, $date);
$licenseRows = auth_salida_daily_report_license_rows($pdo, $date);
$recipients = auth_salida_daily_report_recipients($pdo);

if ($dryRun) {
    echo json_encode([
        'dryRun' => true,
        'fecha' => $date->format('Y-m-d'),
        'subject' => auth_salida_daily_report_subject($date),
        'recipients' => array_column($recipients, 'mail'),
        'rows' => $rows,
        'licenseRows' => $licenseRows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(0);
}

$result = auth_salida_daily_report_send($pdo, $config, $date);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

flock($lock, LOCK_UN);
fclose($lock);

exit(($result['ok'] ?? false) ? 0 : 3);
