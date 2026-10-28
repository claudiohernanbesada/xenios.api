<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/_inc/security.php';
xenios_require_service_key();
xenios_rate_limit('auth-salida-upload', 60, 60);
require_once dirname(__DIR__, 2) . '/_inc/auth_salida_archivos.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
$file = $_FILES['archivo'] ?? [];
if (in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
    || (is_int($file['size'] ?? null) && $file['size'] > XENIOS_AUTH_SALIDA_MAX_BYTES)
    || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > XENIOS_AUTH_SALIDA_MAX_BYTES + 1024 * 1024) {
    http_response_code(413);
    exit('El PDF supera los 10 MB.');
}
$tmp = $file['tmp_name'] ?? null;
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    || !is_string($tmp) || !is_string($file['name'] ?? null) || !is_uploaded_file($tmp)
    || filesize($tmp) <= 0 || filesize($tmp) > XENIOS_AUTH_SALIDA_MAX_BYTES
    || strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf'
    || (new finfo(FILEINFO_MIME_TYPE))->file($tmp) !== 'application/pdf'
    || file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') {
    http_response_code(400);
    exit('PDF inválido (máximo 10 MB).');
}
try {
    $name = bin2hex(random_bytes(16)) . '.pdf';
    $path = xenios_asegurar_directorio('auth_salida') . $name;
    if (!move_uploaded_file($tmp, $path)) {
        throw new RuntimeException('No se pudo guardar el PDF');
    }
    if (!chmod($path, 0640)) {
        unlink($path);
        throw new RuntimeException('No se pudieron establecer permisos del PDF');
    }
    if (str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')) {
        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['archivo' => $name], JSON_UNESCAPED_SLASHES);
    } else {
        echo 'Archivo recibido y guardado como: ' . $name;
    }
} catch (Throwable $exception) {
    error_log('[xenios-auth-salida] ' . $exception->getMessage());
    http_response_code(500);
    echo 'No se pudo guardar el archivo.';
}
