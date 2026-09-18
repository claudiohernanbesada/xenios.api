<?php
/**
 * POST https://api.raei.com.ar/notatraslado
 * Devuelve una copia firmada de Nota de Traslado por id. Requiere token API.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_inc/api_tokens.php';

header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(false, 'Metodo no permitido.', 405);
}

if (!sgi_validar_api_token()) {
    json_response(false, 'Token invalido.', 401);
}

$id = trim((string) ($_POST['id'] ?? $_POST['nombre'] ?? ''));
if ($id === '') {
    json_response(false, 'Debe indicar el id del documento.', 400);
}

$nombreSeguro = basename($id);
if ($nombreSeguro === '' || $nombreSeguro !== $id) {
    json_response(false, 'Id de documento invalido.', 400);
}

$path = dirname(__DIR__) . DIRECTORY_SEPARATOR . '_NOTA-TRASLADO' . DIRECTORY_SEPARATOR . $nombreSeguro;
if (!is_file($path)) {
    json_response(false, 'Archivo no encontrado: ' . $nombreSeguro, 404);
}

$mime = detectar_mime($path) ?? 'application/octet-stream';
$permitidos = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($mime, $permitidos, true)) {
    json_response(false, 'Tipo de archivo no permitido.', 400);
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: inline; filename="' . addcslashes($nombreSeguro, '"\\') . '"');
readfile($path);
exit;

function detectar_mime(string $path): ?string
{
    if (!function_exists('finfo_open')) {
        return null;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return null;
    }

    $mime = finfo_file($finfo, $path);
    finfo_close($finfo);

    return is_string($mime) ? $mime : null;
}

function json_response(bool $exito, string $nombre, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['exito' => $exito, 'nombre' => $nombre], JSON_UNESCAPED_UNICODE);
    exit;
}
