<?php
/**
 * GET https://api.raei.com.ar/personal/{nombre.jpg}
 * Rutas desde _inc/rutas_archivos.php (foto_personal).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_inc/stream_archivo.php';

$nombre = basename((string) ($_GET['f'] ?? ''));
if ($nombre === '') {
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (preg_match('#/personal/([^/]+)$#i', $path, $m)) {
        $nombre = basename(rawurldecode($m[1]));
    }
}

if ($nombre === '' || $nombre === 'index.php' || $nombre === 'no-image.png') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'Nombre de archivo requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!sgi_stream_archivo('foto_personal', $nombre, [
    'disposition' => 'inline',
    'validar' => 'sgi_archivo_es_imagen',
])) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'Archivo no encontrado'], JSON_UNESCAPED_UNICODE);
}
