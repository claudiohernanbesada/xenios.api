<?php
// Mapa de carpetas en disco y URLs públicas para avatares, fotos y PDFs de salida
declare(strict_types=1);

/** @var array<string, array{dir: string, dir_absoluta?: string, url: string, url_respaldo?: list<string>}> */
$GLOBALS['SGI_RUTAS_ARCHIVOS'] = [
    // Avatares opcionales de Usuarios.ImgPerfil (SGI web: uploadAvatar/upload.php; móvil: POST /sgi-movil/auth/avatar)
    'avatar_usuario' => [
        'dir' => '/avatar/',
        // Ruta absoluta en el servidor (prioritaria). Misma carpeta que usa el SGI web al subir perfil.
        'dir_absoluta' => 'C:/wamp64/www/api.raei.com.ar/avatar',
        'url' => 'http://api.raei.com.ar/avatar',
        'url_respaldo' => [
            'http://avatar.raei.com.ar.local',
            'http://avatar.raei.com.ar',
            'http://api.raei.com.ar.local/avatar',
        ],
    ],
    // Fotos de legajo en Personal.Foto
    'foto_personal' => [
        'dir' => '/personal/',
        'url' => 'http://api.raei.com.ar/personal',
        'url_respaldo' => [
            'http://personal.raei.com.ar.local',
            'http://personal.raei.com.ar',
            'http://api.raei.com.ar.local/personal',
        ],
    ],
    // Adjuntos de AuthSalida.AuthFile
    'auth_salida' => [
        'dir' => '/authsalida/',
        'url' => 'http://api.raei.com.ar/authsalida',
        'url_respaldo' => [
            'http://authsalida.raei.com.ar.local',
            'http://authsalida.raei.com.ar',
            'http://api.raei.com.ar.local/authsalida',
        ],
    ],
];

// Devuelve el array de repos configurados arriba
function sgi_rutas_archivos(): array
{
    return $GLOBALS['SGI_RUTAS_ARCHIVOS'];
}

// Document root de Apache/WAMP (ej. C:\wamp64\www)
function sgi_document_root(): string
{
    return rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
}

// Ruta absoluta en disco de la carpeta del repositorio
function sgi_ruta_fisica(string $clave): string
{
    $rutas = sgi_rutas_archivos();
    if (!isset($rutas[$clave]['dir'])) {
        throw new InvalidArgumentException("Repositorio de archivos desconocido: {$clave}");
    }
    $rel = $rutas[$clave]['dir'];
    if ($rel[0] !== '/') {
        $rel = '/' . $rel;
    }
    return sgi_document_root() . $rel;
}

// URL base pública del repo (sin barra final)
function sgi_ruta_url(string $clave): string
{
    $rutas = sgi_rutas_archivos();
    if (!isset($rutas[$clave]['url'])) {
        throw new InvalidArgumentException("URL de repositorio desconocida: {$clave}");
    }
    return rtrim((string) $rutas[$clave]['url'], '/');
}

// URLs alternativas por si el archivo no está en este servidor
function sgi_rutas_respaldo(string $clave): array
{
    $rutas = sgi_rutas_archivos();
    $bases = $rutas[$clave]['url_respaldo'] ?? [];
    if (!is_array($bases)) {
        return [];
    }
    $out = [];
    foreach ($bases as $base) {
        if (is_string($base) && trim($base) !== '') {
            $out[] = rtrim(trim($base), '/');
        }
    }
    return array_values(array_unique($out));
}

// Link directo a un archivo dentro del repo
function sgi_url_archivo(string $clave, string $nombreArchivo): string
{
    return sgi_ruta_url($clave) . '/' . rawurlencode(basename($nombreArchivo));
}

// Rutas en disco donde buscar un repo (DOCUMENT_ROOT y sitio api.raei.com.ar en WAMP)
function sgi_rutas_fisicas_candidatas(string $clave): array
{
    $rutas = sgi_rutas_archivos();
    if (!isset($rutas[$clave]['dir'])) {
        return [];
    }
    $rel = ltrim((string) $rutas[$clave]['dir'], '/\\');
    $candidatas = [];

    $abs = $rutas[$clave]['dir_absoluta'] ?? null;
    if (is_string($abs) && trim($abs) !== '') {
        $candidatas[] = rtrim(trim($abs), '/\\');
    }

    $candidatas[] = sgi_ruta_fisica($clave);

    // WAMP: carpeta bajo el sitio api.raei.com.ar si DOCUMENT_ROOT es C:\wamp64\www
    $apiSiteRoot = dirname(__DIR__);
    if ($rel !== '') {
        $candidatas[] = $apiSiteRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    return array_values(array_unique($candidatas));
}

// Path completo en disco si el archivo existe localmente; si no, null
function sgi_path_archivo_local(string $clave, string $nombreArchivo): ?string
{
    $name = basename($nombreArchivo);
    if ($name === '') {
        return null;
    }
    foreach (sgi_rutas_fisicas_candidatas($clave) as $dir) {
        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

// Devuelve la carpeta del repo si ya existe (la crea el backend al subir archivos)
function sgi_asegurar_directorio(string $clave): string
{
    foreach (sgi_rutas_fisicas_candidatas($clave) as $dir) {
        if (is_dir($dir)) {
            return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        }
    }
    $dir = sgi_ruta_fisica($clave);
    if (!is_dir($dir)) {
        throw new RuntimeException("El directorio del repositorio no existe: {$dir}");
    }
    return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
}
