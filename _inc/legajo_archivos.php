<?php
declare(strict_types=1);

require_once __DIR__ . '/env_loader.php';

const XENIOS_LEGAJO_MAX_BYTES = 100 * 1024 * 1024;
const XENIOS_LEGAJO_MIMES = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
];

function xenios_legajo_directorio(): string
{
    return rtrim(getenv('PATH_LEGAJO_PERSONAL') ?: '/var/storage/legajo', '/\\');
}

function xenios_legajo_url(): string
{
    return rtrim(getenv('URL_LEGAJO_PERSONAL') ?: 'https://api.xenios.com.ar/legajo', '/');
}

function xenios_legajo_nombre_valido(string $name): bool
{
    return (bool) preg_match('/\A(?:[a-f0-9]{32}|[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12})\.(?:pdf|jpe?g|png|gif)\z/i', $name);
}

function xenios_legajo_responder(int $status, array $data): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Publicación atómica sin reemplazar documentos existentes. */
function xenios_legajo_guardar(string $source, string $name): bool
{
    $dir = xenios_legajo_directorio();
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Directorio privado de legajos no disponible');
    }
    $staging = $dir . '/.upload-' . bin2hex(random_bytes(16));
    try {
        if (!move_uploaded_file($source, $staging) || !chmod($staging, 0640)) {
            throw new RuntimeException('No se pudo guardar el documento');
        }
        $target = $dir . '/' . $name;
        if (!@link($staging, $target)) {
            if (file_exists($target) || is_link($target)) {
                return false;
            }
            throw new RuntimeException('No se pudo publicar el documento');
        }
        return true;
    } finally {
        if (is_file($staging)) {
            unlink($staging);
        }
    }
}
