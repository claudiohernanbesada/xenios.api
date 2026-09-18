<?php
/**
 * Subida de copia firmada de Nota de Traslado.
 * Guarda archivos en /_NOTA-TRASLADO/ y requiere token API.
 *
 * Campo multipart: archivo
 * Respuesta JSON: { exito, nombre, id }
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_inc/api_tokens.php';

header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_response(false, 'Metodo no permitido.', 405);
}

if (!sgi_validar_api_token()) {
    json_response(false, 'Token invalido.', 401);
}

$baseDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '_NOTA-TRASLADO';
if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true)) {
    json_response(false, 'No se pudo preparar el directorio de documentos.', 500);
}

if (!isset($_FILES['archivo']) || !is_array($_FILES['archivo'])) {
    json_response(false, 'No se recibio ningun archivo valido.', 400);
}

$file = $_FILES['archivo'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
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
    $id = bin2hex(random_bytes(16)) . '.' . $extension;
    $destino = $baseDir . DIRECTORY_SEPARATOR . $id;
} while (file_exists($destino));

if (!move_uploaded_file($tmp, $destino)) {
    json_response(false, 'No se pudo guardar el archivo.', 500);
}

json_response(true, $id, 200, $id);

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

function json_response(bool $exito, string $nombre, int $status = 200, ?string $id = null): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    $payload = ['exito' => $exito, 'nombre' => $nombre];
    if ($id !== null) {
        $payload['id'] = $id;
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
