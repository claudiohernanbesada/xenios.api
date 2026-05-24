<?php
/**
 * Subida de avatar para el SGI web (ProfileController).
 * App móvil: NO usar este script; debe usar POST /sgi-movil/auth/avatar (la API resuelve rutas).
 * Ambos guardan vía avatar_usuario en _inc/rutas_archivos.php.
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
    echo 'Imagen inválida. Use JPG o PNG de hasta 100 KB.';
    exit;
}

echo 'Archivo recibido y guardado como: ' . $fileName;
