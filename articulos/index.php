<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_inc/security.php';
xenios_require_service_key();
require_once dirname(__DIR__) . '/_inc/rutas_archivos.php';

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
$name = $_GET['f'] ?? '';
if (!is_string($name) || !preg_match('/\A[a-zA-Z0-9_-]{1,100}\.(?:jpe?g|png)\z/i', $name)) {
    http_response_code(400);
    exit;
}
$dir = realpath(xenios_ruta_fisica_escritura('imagen_articulo'));
$path = $dir === false ? false : realpath($dir . '/' . $name);
if ($path === false || dirname($path) !== $dir || is_link($dir . '/' . $name) || !is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . (strtolower(pathinfo($name, PATHINFO_EXTENSION)) === 'png' ? 'image/png' : 'image/jpeg'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: private, no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    readfile($path);
}
