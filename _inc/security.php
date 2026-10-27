<?php
declare(strict_types=1);

require_once __DIR__ . '/api_tokens.php';

function xenios_security_headers(): void
{
    ini_set('display_errors', '0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
    header_remove('X-Powered-By');
}

function xenios_require_service_key(): void
{
    xenios_security_headers();
    $expected = (string) (getenv('XENIOS_SERVICE_TOKEN') ?: '');
    $provided = (string) ($_SERVER['HTTP_X_SERVICE_KEY'] ?? '');
    if (strlen($expected) < 32 || !hash_equals($expected, $provided)) {
        http_response_code(401);
        exit('No autorizado');
    }
}

/** Atomic per-IP fixed window. Keep this directory outside the document root. */
function xenios_rate_limit(string $scope, int $limit, int $seconds): void
{
    $dir = getenv('XENIOS_RATE_LIMIT_DIR') ?: sys_get_temp_dir() . '/xenios-api-ratelimit';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        http_response_code(503);
        exit('Servicio temporalmente no disponible');
    }
    // Do not trust arbitrary forwarding headers supplied by clients.
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $file = $dir . '/' . hash('sha256', $scope . ':' . $ip);
    $handle = @fopen($file, 'c+');
    if (!$handle || !flock($handle, LOCK_EX)) {
        if ($handle) { fclose($handle); }
        http_response_code(503);
        exit('Servicio temporalmente no disponible');
    }
    $state = json_decode(stream_get_contents($handle) ?: '{}', true);
    $now = time();
    if (!is_array($state) || ($state['until'] ?? 0) <= $now) {
        $state = ['until' => $now + $seconds, 'count' => 0];
    }
    $state['count']++;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    if ($state['count'] > $limit) {
        header('Retry-After: ' . max(1, $state['until'] - $now));
        http_response_code(429);
        exit('Demasiadas solicitudes. Intentá más tarde.');
    }
}
