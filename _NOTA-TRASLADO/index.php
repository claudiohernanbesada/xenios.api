<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_inc/api_tokens.php';

header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Metodo no permitido.', 405);
}

if (!sgi_validar_api_token()) {
    json_response(false, 'Token invalido.', 401);
}

$baseDir = __DIR__;

if (isset($_FILES['archivo'])) {
    upload_documento($baseDir);
}

$nombre = trim((string) ($_POST['nombre'] ?? ''));
if ($nombre !== '') {
    stream_documento($baseDir, $nombre);
}

json_response(false, 'Solicitud invalida.', 400);

function upload_documento(string $baseDir): void
{
    $file = $_FILES['archivo'];

    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(false, 'No se recibio ningun archivo valido.', 400);
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        json_response(false, 'Archivo temporal invalido.', 400);
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    if (!isset($allowed[$extension])) {
        json_response(false, 'Tipo de archivo no permitido.', 400);
    }

    $maxBytes = 25 * 1024 * 1024;
    if ((int) ($file['size'] ?? 0) > $maxBytes) {
        json_response(false, 'El archivo supera el maximo permitido.', 400);
    }

    $mime = detectar_mime($tmp);
    if ($mime !== null && $mime !== $allowed[$extension]) {
        json_response(false, 'El contenido del archivo no coincide con la extension.', 400);
    }

    do {
        $nombre = bin2hex(random_bytes(16)) . '.' . $extension;
        $destino = $baseDir . DIRECTORY_SEPARATOR . $nombre;
    } while (file_exists($destino));

    if (!move_uploaded_file($tmp, $destino)) {
        json_response(false, 'No se pudo guardar el archivo.', 500);
    }

    json_response(true, $nombre);
}

function stream_documento(string $baseDir, string $nombre): void
{
    $nombreSeguro = basename($nombre);
    if ($nombreSeguro === '' || $nombreSeguro !== $nombre) {
        json_response(false, 'Nombre de archivo invalido.', 400);
    }

    $path = $baseDir . DIRECTORY_SEPARATOR . $nombreSeguro;
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
}

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
