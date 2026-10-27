<?php

declare(strict_types=1);

// Avatar opcional (Usuarios.ImgPerfil) — repo avatar_usuario en api.xenios.com.ar (/avatar/)
// XENIOS web: POST .../actions/uploadAvatar/upload.php · Móvil: POST .../xenios-movil/auth/avatar
const XENIOS_AVATAR_REPO = 'avatar_usuario';

/** Mismo límite que ProfileController.UploadImage en XENIOS web (100 KB) */
const XENIOS_AVATAR_MAX_BYTES = 100 * 1024;

require_once dirname(__DIR__, 2) . '/_inc/rutas_archivos.php';
require_once dirname(__DIR__, 2) . '/_inc/stream_archivo.php';

// Borra el archivo viejo del disco si no es el placeholder no-image.png
function delete_archivo_fisico(string $claveRepositorio, ?string $nombreArchivo): void
{
    if ($nombreArchivo === null || $nombreArchivo === '' || $nombreArchivo === 'no-image.png') {
        return;
    }
    $path = xenios_path_archivo_local($claveRepositorio, $nombreArchivo);
    if ($path !== null) {
        @unlink($path);
    }
}

/** @deprecated use delete_archivo_fisico('foto_personal', ...) */
function delete_personal_foto_file(?string $foto, array $config): void
{
    delete_archivo_fisico('foto_personal', $foto);
}

// Carpeta de subida según rutas_archivos.php (api.xenios.com.ar + /avatar/)
function ensure_upload_directory(string $claveRepositorio): string
{
    return xenios_asegurar_directorio($claveRepositorio);
}

// Guarda JPG/PNG con nombre aleatorio en el repo indicado
function save_uploaded_foto(array $file, string $claveRepositorio, ?int $maxBytes = null): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $limit = $maxBytes ?? ($claveRepositorio === XENIOS_AVATAR_REPO ? XENIOS_AVATAR_MAX_BYTES : 512 * 1024);
    if (($file['size'] ?? 0) > $limit) {
        return null;
    }

    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        return null;
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    $image = @getimagesize($tmp);
    $expected = $ext === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
    if (!is_uploaded_file($tmp) || !$image || $image[2] !== $expected || $image[0] * $image[1] > 20000000) {
        return null;
    }

    $extension = $ext === 'jpeg' ? '.jpg' : '.' . $ext;
    $fileName = bin2hex(random_bytes(16)) . $extension;

    $dir = ensure_upload_directory($claveRepositorio);
    $destino = $dir . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        $err = error_get_last();
        $detail = isset($err['message']) ? (string) $err['message'] : 'move_uploaded_file falló';
        throw new RuntimeException('No se pudo guardar el archivo en ' . $destino . ' (' . $detail . ')');
    }

    return $fileName;
}

// URL pública del archivo: primero local, si no hay, URL de respaldo
function resolve_url_archivo_publico(string $claveRepositorio, string $fileName): string
{
    $name = basename($fileName);
    $local = xenios_path_archivo_local($claveRepositorio, $name);
    if ($local !== null) {
        return xenios_url_archivo($claveRepositorio, $name);
    }

    foreach (xenios_rutas_respaldo($claveRepositorio) as $base) {
        return $base . '/' . rawurlencode($name);
    }

    return xenios_url_archivo($claveRepositorio, $name);
}

// Avatar de la app móvil: solo Usuarios.ImgPerfil (no Personal.Foto de legajo)
function resolve_avatar_url(array $user, array $config): ?string
{
    $img = $user['ImgPerfil'] ?? null;
    if (is_string($img) && $img !== '' && $img !== 'no-image.png') {
        return resolve_url_archivo_publico(XENIOS_AVATAR_REPO, $img);
    }

    return null;
}

// Imagen de un repositorio (dir + url_respaldo de rutas_archivos.php)
function stream_repositorio_imagen(string $claveRepositorio, string $fileName): bool
{
    return xenios_stream_archivo($claveRepositorio, $fileName, [
        'disposition' => 'inline',
    ]);
}

// Imagen de avatar opcional del usuario (Usuarios.ImgPerfil → repo avatar_usuario)
function stream_avatar_for_user(array $user): bool
{
    $img = $user['ImgPerfil'] ?? null;
    if (is_string($img) && $img !== '' && $img !== 'no-image.png') {
        return stream_repositorio_imagen(XENIOS_AVATAR_REPO, $img);
    }

    return false;
}
