<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_inc/security.php';
xenios_require_service_key();
require_once dirname(__DIR__) . '/_inc/legajo_archivos.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    xenios_legajo_responder(405, ['message' => 'Método no permitido']);
}
$name = $_GET['f'] ?? '';
if (!is_string($name) || !xenios_legajo_nombre_valido($name)) {
    xenios_legajo_responder(400, ['message' => 'Nombre de documento inválido']);
}
$dir = realpath(xenios_legajo_directorio());
$path = $dir === false ? false : realpath($dir . '/' . $name);
if ($path === false || dirname($path) !== $dir || !is_file($path) || !is_readable($path)
    || is_link($dir . '/' . $name)) {
    xenios_legajo_responder(404, ['message' => 'Documento no encontrado']);
}

header('Content-Type: ' . XENIOS_LEGAJO_MIMES[strtolower(pathinfo($name, PATHINFO_EXTENSION))]);
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
if ($method === 'GET') {
    readfile($path);
}
