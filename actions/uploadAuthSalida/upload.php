<?php
/**
 * Subida de PDF firmado de autorización de salida (compatible con SGI web).
 * Guarda en /authsalida/ del sitio api.raei.com.ar
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo 'No se recibió ningún archivo válido.';
    exit;
}

$directorio = '/authsalida/';
$nombre = basename($_FILES['archivo']['name']);
$tmp = $_FILES['archivo']['tmp_name'];
$destino = $_SERVER['DOCUMENT_ROOT'] . $directorio . $nombre;

if (!is_dir($_SERVER['DOCUMENT_ROOT'] . $directorio)) {
    mkdir($_SERVER['DOCUMENT_ROOT'] . $directorio, 0755, true);
}

if (move_uploaded_file($tmp, $destino)) {
    echo 'Archivo recibido y guardado como: ' . $nombre;
} else {
    http_response_code(500);
    echo 'Error al mover el archivo.';
}
