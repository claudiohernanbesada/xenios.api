<?php
declare(strict_types=1);
require_once __DIR__ . '/rutas_archivos.php';

const XENIOS_AUTH_SALIDA_MAX_BYTES = 10 * 1024 * 1024;

function xenios_auth_salida_nombre_valido(string $name): bool
{
    return (bool) preg_match('/\A[a-zA-Z0-9_-]{1,100}\.pdf\z/i', $name);
}

function xenios_auth_salida_stream(string $name, string $disposition = 'attachment'): bool
{
    if (!xenios_auth_salida_nombre_valido($name)) {
        return false;
    }
    $dir = realpath(xenios_ruta_fisica_escritura('auth_salida'));
    $path = $dir === false ? false : realpath($dir . '/' . $name);
    if ($path === false || dirname($path) !== $dir || !is_file($path) || !is_readable($path)
        || is_link($dir . '/' . $name) || filesize($path) > XENIOS_AUTH_SALIDA_MAX_BYTES
        || file_get_contents($path, false, null, 0, 5) !== '%PDF-') {
        return false;
    }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($disposition === 'inline' ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header('Cache-Control: private, no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($path);
    }
    return true;
}
