<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/_inc/security.php';
xenios_require_service_key();
require_once dirname(__DIR__, 2) . '/xenios-movil/lib/avatar.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit; }
try {
    $name = save_uploaded_foto($_FILES['archivo'] ?? [], 'avatar_usuario');
    if ($name === null) { http_response_code(400); exit('Imagen inválida.'); }
    echo 'Archivo recibido y guardado como: ' . $name;
} catch (Throwable $e) {
    error_log('[xenios-upload] ' . $e->getMessage());
    http_response_code(500);
    echo 'No se pudo guardar el archivo.';
}
