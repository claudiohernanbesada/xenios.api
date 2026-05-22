<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/_inc/rutas_archivos.php';

// Borra el archivo viejo del disco si no es el placeholder no-image.png
function delete_archivo_fisico(string $claveRepositorio, ?string $nombreArchivo): void
{
    if ($nombreArchivo === null || $nombreArchivo === '' || $nombreArchivo === 'no-image.png') {
        return;
    }
    $path = sgi_path_archivo_local($claveRepositorio, $nombreArchivo);
    if ($path !== null) {
        @unlink($path);
    }
}

/** @deprecated use delete_archivo_fisico('foto_personal', ...) */
function delete_personal_foto_file(?string $foto, array $config): void
{
    delete_archivo_fisico('foto_personal', $foto);
}

// Guarda JPG/PNG de hasta 100 KB con nombre aleatorio en el repo indicado
function save_uploaded_foto(array $file, string $claveRepositorio): ?string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if ($file['size'] > 100 * 1024) {
        return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        return null;
    }

    $extension = $ext === 'jpeg' ? '.jpg' : '.' . $ext;
    $fileName = bin2hex(random_bytes(16)) . $extension;

    $dir = sgi_asegurar_directorio($claveRepositorio);
    $destino = $dir . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        return null;
    }

    return $fileName;
}

// URL pública del archivo: primero local, si no hay, URL de respaldo
function resolve_url_archivo_publico(string $claveRepositorio, string $fileName): string
{
    $name = basename($fileName);
    $local = sgi_path_archivo_local($claveRepositorio, $name);
    if ($local !== null) {
        return sgi_url_archivo($claveRepositorio, $name);
    }

    foreach (sgi_rutas_respaldo($claveRepositorio) as $base) {
        return $base . '/' . rawurlencode($name);
    }

    return sgi_url_archivo($claveRepositorio, $name);
}

// Prioriza foto de Personal; si no hay, ImgPerfil del Usuario
function resolve_avatar_url(?array $personal, array $user, array $config): ?string
{
    $foto = $personal['Foto'] ?? null;
    if (is_string($foto) && $foto !== '' && $foto !== 'no-image.png') {
        return resolve_url_archivo_publico('foto_personal', $foto);
    }

    $img = $user['ImgPerfil'] ?? null;
    if (is_string($img) && $img !== '' && $img !== 'no-image.png') {
        return resolve_url_archivo_publico('avatar_usuario', $img);
    }

    return null;
}
