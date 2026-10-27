<?php
/**
 * GET https://api.xenios.com.ar/avatar/{nombre.jpg}
 * Rutas desde _inc/rutas_archivos.php (avatar_usuario).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_inc/security.php';
xenios_require_service_key();
require_once dirname(__DIR__) . '/_inc/stream_archivo.php';

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
