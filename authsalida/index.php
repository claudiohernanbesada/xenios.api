<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_inc/security.php';
xenios_require_service_key();
require_once dirname(__DIR__) . '/_inc/auth_salida_archivos.php';
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}
$name = $_GET['f'] ?? '';
if (!is_string($name) || !xenios_auth_salida_nombre_valido($name)) {
    http_response_code(400);
    exit('Nombre de documento inválido.');
}
if (!xenios_auth_salida_stream($name, 'inline')) {
    http_response_code(404);
    exit('Documento no encontrado.');
}
