<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/_inc/security.php';
xenios_require_service_key();
require_once dirname(__DIR__, 2) . '/_inc/rutas_archivos.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
$file = $_FILES['archivo'] ?? [];
$tmp = (string) ($file['tmp_name'] ?? '');
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || ($file['size'] ?? 0) > 10 * 1024 * 1024 || !is_uploaded_file($tmp)
    || strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'pdf'
    || (new finfo(FILEINFO_MIME_TYPE))->file($tmp) !== 'application/pdf'
    || file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') {
    http_response_code(400); exit('PDF inválido (máximo 10 MB).');
}
try {
    $name = bin2hex(random_bytes(16)) . '.pdf';
    if (!move_uploaded_file($tmp, xenios_asegurar_directorio('auth_salida') . $name)) {
        throw new RuntimeException('No se pudo guardar PDF');
    }
    echo 'Archivo recibido y guardado como: ' . $name;
} catch (Throwable $e) {
    error_log('[xenios-upload] ' . $e->getMessage());
    http_response_code(500); echo 'No se pudo guardar el archivo.';
}
