<?php
/**
 * Subida de avatar de perfil (compatible con SGI web / ProfileController).
 * Guarda en el repositorio avatar_usuario de _inc/rutas_archivos.php
 * (misma carpeta que consume POST /sgi-movil/auth/avatar en la app móvil).
 *
 * Campo multipart: archivo
 * Respuesta: texto plano "Archivo recibido y guardado como: {nombre}"
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_inc/rutas_archivos.php';
require_once dirname(__DIR__, 2) . '/sgi-movil/lib/avatar.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

if (!isset($_FILES['archivo']) || ($_FILES['archivo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo 'No se recibió ningún archivo válido.';
    exit;
}

try {
    $fileName = save_uploaded_foto($_FILES['archivo'], 'avatar_usuario');
} catch (Throwable $e) {
    http_response_code(500);
    echo $e->getMessage();
    exit;
}

if ($fileName === null) {
    http_response_code(400);
    echo 'Imagen inválida. Use JPG o PNG de hasta 512 KB.';
    exit;
}

echo 'Archivo recibido y guardado como: ' . $fileName;
