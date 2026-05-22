<?php

declare(strict_types=1);

// Subida, borrado y URLs de avatares/fotos de perfil
require_once dirname(__DIR__, 2) . '/_inc/rutas_archivos.php';
require_once dirname(__DIR__, 2) . '/_inc/stream_archivo.php';

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

// Carpeta de subida: usa la existente o la crea (solo al subir, como uploadAuthSalida)
function ensure_upload_directory(string $claveRepositorio): string
{
    foreach (sgi_rutas_fisicas_candidatas($claveRepositorio) as $dir) {
        if (is_dir($dir)) {
            return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        }
    }

    $candidatas = sgi_rutas_fisicas_candidatas($claveRepositorio);
    $target = $candidatas[count($candidatas) - 1] ?? sgi_ruta_fisica($claveRepositorio);
    if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
        throw new RuntimeException('No se pudo preparar la carpeta del repositorio: ' . $target);
    }

    return rtrim($target, '/\\') . DIRECTORY_SEPARATOR;
}

// Guarda JPG/PNG con nombre aleatorio en el repo indicado
function save_uploaded_foto(array $file, string $claveRepositorio): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($file['size'] ?? 0) > 512 * 1024) {
        return null;
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        return null;
    }

    $extension = $ext === 'jpeg' ? '.jpg' : '.' . $ext;
    $fileName = bin2hex(random_bytes(16)) . $extension;

    $dir = ensure_upload_directory($claveRepositorio);
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

// Avatar de la app móvil: solo Usuarios.ImgPerfil (no Personal.Foto de legajo)
function resolve_avatar_url(array $user, array $config): ?string
{
    $img = $user['ImgPerfil'] ?? null;
    if (is_string($img) && $img !== '' && $img !== 'no-image.png') {
        return resolve_url_archivo_publico('avatar_usuario', $img);
    }

    return null;
}

// Imagen de un repositorio (dir + url_respaldo de rutas_archivos.php)
function stream_repositorio_imagen(string $claveRepositorio, string $fileName): bool
{
    return sgi_stream_archivo($claveRepositorio, $fileName, [
        'disposition' => 'inline',
    ]);
}

// Imagen de avatar opcional del usuario (Usuarios.ImgPerfil → repo avatar_usuario)
function stream_avatar_for_user(array $user): bool
{
    $img = $user['ImgPerfil'] ?? null;
    if (is_string($img) && $img !== '' && $img !== 'no-image.png') {
        return stream_repositorio_imagen('avatar_usuario', $img);
    }

    return false;
}
