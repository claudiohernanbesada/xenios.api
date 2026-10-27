<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_inc/security.php';
xenios_require_service_key();
xenios_rate_limit('legajo-upload', 60, 60);
require_once dirname(__DIR__, 2) . '/_inc/legajo_archivos.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    xenios_legajo_responder(405, ['message' => 'Método no permitido']);
}

$file = $_FILES['archivo'] ?? [];
$error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE
    || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > XENIOS_LEGAJO_MAX_BYTES + 1024 * 1024) {
    xenios_legajo_responder(413, ['message' => 'El documento supera los 100 MB']);
}
if (!is_int($error) || $error !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
    || !is_string($file['name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
    xenios_legajo_responder(400, ['message' => 'Enviar un documento en el campo archivo']);
}

$tmp = $file['tmp_name'];
$size = filesize($tmp);
if ($size === false || $size === 0 || $size > XENIOS_LEGAJO_MAX_BYTES) {
    xenios_legajo_responder($size > XENIOS_LEGAJO_MAX_BYTES ? 413 : 400, ['message' => 'Tamaño de documento inválido (máximo 100 MB)']);
}
$extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
if (!isset(XENIOS_LEGAJO_MIMES[$extension]) || XENIOS_LEGAJO_MIMES[$extension] !== $mime) {
    xenios_legajo_responder(400, ['message' => 'Documento inválido. Formatos permitidos: PDF, JPG, PNG y GIF']);
}
if ($extension === 'pdf') {
    if (file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') {
        xenios_legajo_responder(400, ['message' => 'PDF inválido']);
    }
} else {
    $image = @getimagesize($tmp);
    if (!$image || $image['mime'] !== $mime || $image[0] * $image[1] > 20000000) {
        xenios_legajo_responder(400, ['message' => 'Imagen inválida o demasiado grande']);
    }
}

// El consumidor web persiste este GUID antes de procesar la respuesta.
$name = xenios_legajo_nombre_valido($file['name'])
    ? $file['name'] : bin2hex(random_bytes(16)) . '.' . $extension;
try {
    if (!xenios_legajo_guardar($tmp, $name)) {
        xenios_legajo_responder(409, ['message' => 'Ya existe un documento con ese nombre']);
    }
    xenios_legajo_responder(201, [
        'archivo' => $name,
        'url' => xenios_legajo_url() . '/' . rawurlencode($name),
        'tamano' => $size,
        'tipo' => $mime,
    ]);
} catch (Throwable $exception) {
    error_log('[xenios-legajo] ' . $exception->getMessage());
    xenios_legajo_responder(500, ['message' => 'No se pudo guardar el documento']);
}
