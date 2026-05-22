<?php
/**
 * GET http://api.raei.com.ar/authsalida/{nombre.pdf}
 * Resuelve rutas desde _inc/rutas_archivos.php (dir local + url_respaldo).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_inc/stream_archivo.php';

$nombre = basename((string) ($_GET['f'] ?? ''));
if ($nombre === '') {
    $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    if (preg_match('#/authsalida/([^/]+)$#i', $path, $m)) {
        $nombre = basename(rawurldecode($m[1]));
    }
}

if ($nombre === '' || $nombre === 'index.php') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'Nombre de archivo requerido'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!sgi_stream_archivo('auth_salida', $nombre, [
    'disposition' => 'inline',
    'validar' => static fn (string $b): bool => sgi_archivo_es_pdf($b) || sgi_archivo_es_imagen($b),
])) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => 'Archivo no encontrado'], JSON_UNESCAPED_UNICODE);
}
