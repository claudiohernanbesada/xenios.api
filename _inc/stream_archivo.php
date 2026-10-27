<?php
// Sirve archivos leyendo rutas de rutas_archivos.php (disco local + url_respaldo)
declare(strict_types=1);

require_once __DIR__ . '/rutas_archivos.php';

// MIME según extensión
function xenios_archivo_mime(string $fileName): string
{
    return match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'jpg', 'jpeg' => 'image/jpeg',
        default => 'application/octet-stream',
    };
}

// Valida bytes de PDF
function xenios_archivo_es_pdf(string $bytes): bool
{
    return strlen($bytes) >= 4 && str_starts_with($bytes, '%PDF');
}

// Valida bytes de imagen
function xenios_archivo_es_imagen(string $bytes): bool
{
    if (strlen($bytes) < 12) {
        return false;
    }
    if (str_starts_with($bytes, "\x89PNG") || str_starts_with($bytes, 'GIF8')) {
        return true;
    }
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        return true;
    }
    return str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP');
}

// URLs remotas definidas en url_respaldo (no la URL pública propia, evita bucles)
function xenios_archivo_urls_respaldo(string $clave, string $nombreArchivo): array
{
    $name = basename($nombreArchivo);
    if ($name === '') {
        return [];
    }

    $urls = [];
    foreach (xenios_rutas_respaldo($clave) as $base) {
        $urls[] = $base . '/' . rawurlencode($name);
    }


    return array_values(array_unique($urls));
}

// Descarga desde url_respaldo del archivo de configuración
function xenios_fetch_archivo_remoto(string $clave, string $nombreArchivo, ?callable $validar = null): ?string
{
    $name = basename($nombreArchivo);
    if ($name === '') {
        return null;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 25,
            'ignore_errors' => false,
            'follow_location' => 0,
            'header' => "Accept: application/pdf,image/*,application/octet-stream,*/*\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    foreach (xenios_archivo_urls_respaldo($clave, $nombreArchivo) as $url) {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') { continue; }
        $body = @file_get_contents($url, false, $ctx, 0, 10 * 1024 * 1024 + 1);
        if (is_string($body) && strlen($body) > 10 * 1024 * 1024) { continue; }
        if ($body === false || strlen($body) < 50) {
            continue;
        }
        if ($validar !== null && !$validar($body)) {
            continue;
        }
        if ($validar === null && (str_starts_with(trim($body), '<') && !xenios_archivo_es_pdf($body))) {
            continue;
        }
        return $body;
    }

    return null;
}

// Envía bytes al cliente
function xenios_archivo_send(string $fileName, string $bytes, string $disposition = 'inline'): void
{
    $safe = basename($fileName);
    header('Content-Type: ' . xenios_archivo_mime($safe));
    header('Content-Length: ' . (string) strlen($bytes));
    header('Content-Disposition: ' . $disposition . '; filename="' . $safe . '"');
    header('Cache-Control: private, max-age=300');
    echo $bytes;
}

/**
 * @param array{disposition?: string, validar?: callable|null} $opciones
 */
function xenios_stream_archivo(string $clave, string $nombreArchivo, array $opciones = []): bool
{
    $name = basename($nombreArchivo);
    if ($name === '' || $name === 'no-image.png') {
        return false;
    }

    $disposition = (string) ($opciones['disposition'] ?? 'inline');
    $validar = $opciones['validar'] ?? null;

    $local = xenios_path_archivo_local($clave, $name);
    if ($local !== null) {
        xenios_archivo_send($name, (string) file_get_contents($local), $disposition);
        return true;
    }

    $remoto = xenios_fetch_archivo_remoto($clave, $nombreArchivo, $validar);
    if ($remoto !== null) {
        xenios_archivo_send($name, $remoto, $disposition);
        return true;
    }

    return false;
}
