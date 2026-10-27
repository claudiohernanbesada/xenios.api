<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/fichadas_push.php';

$config = require dirname(__DIR__) . '/config.php';
$lookbackHours = isset($argv[1]) ? (int) $argv[1] : 24;
$limit = isset($argv[2]) ? (int) $argv[2] : 200;
$baselineOnly = in_array('--baseline', $argv, true);

try {
    $pdo = db_connect($config);
    $result = fichadas_push_procesar($pdo, $lookbackHours, $limit, $baselineOnly);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(($result['ok'] ?? false) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, '[fichadas_push] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
