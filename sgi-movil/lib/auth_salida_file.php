<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_inc/rutas_archivos.php';

// Clave del repo donde viven los PDFs de autorización de salida
const SGI_REPO_AUTH_SALIDA = 'auth_salida';

// Content-Type según extensión del archivo adjunto
function auth_salida_mime(string $fileName): string
{
    return match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        default => 'application/octet-stream',
    };
}

// Headers para descargar el adjunto como attachment
function auth_salida_send_headers(string $fileName, int $length): void
{
    header('Content-Type: ' . auth_salida_mime($fileName));
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . (string) $length);
}

// Intenta bajar el PDF desde URLs de respaldo y lo cachea en disco si sirve
function auth_salida_fetch_remote_bytes(string $authFile): ?string
{
    $name = basename($authFile);
    if ($name === '') {
        return null;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 25,
            'ignore_errors' => true,
            'header' => "Accept: application/pdf,application/octet-stream,*/*\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    foreach (sgi_rutas_respaldo(SGI_REPO_AUTH_SALIDA) as $base) {
        $url = $base . '/' . rawurlencode($name);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false || strlen($body) < 50) {
            continue;
        }
        if (str_starts_with($body, '%PDF') || str_starts_with($body, "\x89PNG")) {
            auth_salida_cache_local($name, $body);
            return $body;
        }
    }

    return null;
}

// Guarda en /authsalida/ local lo que bajamos del servidor remoto
function auth_salida_cache_local(string $fileName, string $bytes): void
{
    $path = sgi_path_archivo_local(SGI_REPO_AUTH_SALIDA, $fileName);
    if ($path === null) {
        $dir = sgi_asegurar_directorio(SGI_REPO_AUTH_SALIDA);
        @file_put_contents($dir . basename($fileName), $bytes);
    }
}

// Sirve el PDF desde disco o, si falta, lo trae de la URL de respaldo
function stream_auth_salida_file(string $authFile, array $config): bool
{
    $name = basename($authFile);
    $local = sgi_path_archivo_local(SGI_REPO_AUTH_SALIDA, $name);
    if ($local !== null) {
        auth_salida_send_headers($name, (int) filesize($local));
        readfile($local);
        return true;
    }

    $remote = auth_salida_fetch_remote_bytes($authFile);
    if ($remote !== null) {
        auth_salida_send_headers($name, strlen($remote));
        echo $remote;
        return true;
    }

    return false;
}
