<?php
// Mapa de carpetas en disco y URLs públicas para avatares, fotos y PDFs de salida
declare(strict_types=1);

/** @var array<string, array{dir: string, dir_absoluta?: string, url: string, url_respaldo?: list<string>}> */
$GLOBALS['SGI_RUTAS_ARCHIVOS'] = [
    // Avatares Usuarios.ImgPerfil — repo propio avatar.raei.com.ar (NO carpeta /avatar/ del vhost api)
    'avatar_usuario' => [
        'dir' => '/',
        // Obligatorio: carpeta del vhost/servidor de avatares (misma que URL_PERFIL_USER del SGI web)
        'dir_absoluta' => 'C:/wamp64/www/avatar.raei.com.ar',
        'url' => 'http://avatar.raei.com.ar.local',
        'url_respaldo' => [
            'http://avatar.raei.com.ar',
            'http://api.raei.com.ar/avatar',
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

// Carpeta donde SUBIR archivos (solo dir_absoluta o sitio api + dir; avatar exige dir_absoluta)
function sgi_ruta_fisica_escritura(string $clave): string
{
    $rutas = sgi_rutas_archivos();
    if (!isset($rutas[$clave]['dir'])) {
        throw new InvalidArgumentException("Repositorio de archivos desconocido: {$clave}");
    }

    $abs = $rutas[$clave]['dir_absoluta'] ?? null;
    if (is_string($abs) && trim($abs) !== '') {
        return rtrim(trim($abs), '/\\');
    }

    if ($clave === 'avatar_usuario') {
        throw new RuntimeException(
            'avatar_usuario: configure dir_absoluta en rutas_archivos.php (repositorio avatar.raei.com.ar, no el vhost api).'
        );
    }

    $rel = ltrim((string) $rutas[$clave]['dir'], '/\\');
    $apiSiteRoot = dirname(__DIR__);
    if ($rel !== '' && $rel !== '/') {
        return $apiSiteRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    }

    return rtrim($apiSiteRoot, '/\\');
}

// Rutas en disco donde LEER un archivo (repo configurado + ubicaciones legacy)
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

    $apiSiteRoot = dirname(__DIR__);
    if ($clave === 'avatar_usuario') {
        // Archivos subidos por error al vhost api (migración / lectura)
        $candidatas[] = $apiSiteRoot . DIRECTORY_SEPARATOR . 'avatar';
    } elseif ($rel !== '' && $rel !== '/') {
        $candidatas[] = $apiSiteRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $candidatas[] = sgi_ruta_fisica($clave);
    } else {
        $candidatas[] = sgi_ruta_fisica($clave);
        if ($rel !== '') {
            $candidatas[] = $apiSiteRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        }
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

// Devuelve la carpeta del repo para escritura (debe existir o crearse al subir)
function sgi_asegurar_directorio(string $clave): string
{
    $dir = sgi_ruta_fisica_escritura($clave);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("El directorio del repositorio no existe: {$dir}");
    }
    return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
}
