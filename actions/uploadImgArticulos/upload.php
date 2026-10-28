<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/_inc/security.php';
xenios_require_service_key();
xenios_rate_limit('articulos-upload', 60, 60);
require_once dirname(__DIR__, 2) . '/xenios-movil/lib/avatar.php';

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['message' => 'Método no permitido']);
    exit;
}
$file = $_FILES['archivo'] ?? [];
if (in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
    || (is_int($file['size'] ?? null) && $file['size'] > 512 * 1024)) {
    http_response_code(413);
    echo json_encode(['message' => 'La imagen supera los 512 KB']);
    exit;
}
try {
    $name = save_uploaded_foto($file, 'imagen_articulo', 512 * 1024);
    if ($name === null) {
        http_response_code(400);
        echo json_encode(['message' => 'Enviar una imagen JPG o PNG válida de hasta 512 KB']);
        exit;
    }
    $path = xenios_ruta_fisica_escritura('imagen_articulo') . '/' . $name;
    if (!chmod($path, 0640)) {
        unlink($path);
        throw new RuntimeException('No se pudieron establecer permisos de la imagen');
    }
    http_response_code(201);
    echo json_encode(['archivo' => $name, 'url' => xenios_url_archivo('imagen_articulo', $name)], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('[xenios-articulos] ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['message' => 'No se pudo guardar la imagen']);
}
