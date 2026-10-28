<?php
/**
 * GET https://api.xenios.com.ar/avatar/{nombre.jpg}
 * Rutas desde _inc/rutas_archivos.php (avatar_usuario).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_inc/security.php';
xenios_require_service_key();
require_once dirname(__DIR__) . '/_inc/stream_archivo.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'DELETE') {
    require_once dirname(__DIR__) . '/xenios-movil/lib/avatar.php';
    require_once dirname(__DIR__) . '/xenios-movil/lib/db.php';
    $name = (string) ($_GET['f'] ?? '');
    if ($name === '') {
        $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        if (preg_match('#/avatar/([^/]+)$#i', $path, $match)) { $name = rawurldecode($match[1]); }
    }
    if ($name === '' || $name === 'no-image.png' || !preg_match('/\A[a-zA-Z0-9_-]{1,100}\.(?:jpg|jpeg|png)\z/i', $name)) {
        http_response_code(400); exit;
    }
    try {
        $config = require dirname(__DIR__) . '/xenios-movil/config.php';
        http_response_code(delete_unused_avatar(db_connect($config), $name) ? 204 : 409);
    } catch (InvalidArgumentException $exception) {
        http_response_code(400);
    } catch (Throwable $exception) {
        error_log('[xenios-avatar-delete] ' . $exception->getMessage());
        http_response_code(500);
    }
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD, DELETE'); http_response_code(405); exit;
}

$nombre = basename((string) ($_GET['f'] ?? ''));
if ($nombre === '') {
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (preg_match('#/avatar/([^/]+)$#i', $path, $m)) {
        $nombre = basename(rawurldecode($m[1]));
    }
}

if ($nombre === '' || $nombre === 'index.php' || $nombre === 'no-image.png') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'Nombre de archivo requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!xenios_stream_archivo('avatar_usuario', $nombre, [
    'disposition' => 'inline',
    'validar' => 'xenios_archivo_es_imagen',
])) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'Archivo no encontrado'], JSON_UNESCAPED_UNICODE);
}
