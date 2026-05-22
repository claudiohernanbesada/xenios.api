<?php
// Mapa de carpetas en disco y URLs públicas (servidor productivo api.raei.com.ar)
declare(strict_types=1);

/** @var array<string, array{dir: string, dir_absoluta?: string, url: string, url_respaldo?: list<string>}> */
$GLOBALS['SGI_RUTAS_ARCHIVOS'] = [
    // Avatares Usuarios.ImgPerfil — carpeta /avatar/ del sitio api.raei.com.ar
    'avatar_usuario' => [
        'dir' => '/avatar/',
        'url' => 'https://api.raei.com.ar/avatar',
        'url_respaldo' => [
            'https://avatar.raei.com.ar',
            'http://api.raei.com.ar/avatar',
        ],
    ],
    'foto_personal' => [
        'dir' => '/personal/',
        'url' => 'https://api.raei.com.ar/personal',
        'url_respaldo' => [
            'https://personal.raei.com.ar',
            'http://api.raei.com.ar/personal',
        ],
    ],
    'auth_salida' => [
        'dir' => '/authsalida/',
        'url' => 'https://api.raei.com.ar/authsalida',
        'url_respaldo' => [
            'https://authsalida.raei.com.ar',
            'http://api.raei.com.ar/authsalida',
        ],
    ],
];

// Devuelve el array de repos configurados arriba
function sgi_rutas_archivos(): array
{
    return $GLOBALS['SGI_RUTAS_ARCHIVOS'];
}

// Raíz del sitio api.raei.com.ar en disco (_inc está dentro de ese árbol)
function sgi_api_site_root(): string
{
    return dirname(__DIR__);
}

// Document root de Apache en este vhost
function sgi_document_root(): string
{
    return rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
}

// Ruta absoluta en disco según DOCUMENT_ROOT + dir del repo
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

// Carpeta del sitio api.raei.com.ar + dir relativo (ej. /avatar/)
function sgi_ruta_fisica_relativa_api(string $clave): string
{
    $rutas = sgi_rutas_archivos();
    $rel = ltrim((string) ($rutas[$clave]['dir'] ?? ''), '/\\');
    $root = sgi_api_site_root();
    if ($rel === '' || $rel === '/') {
        return rtrim($root, '/\\');
    }

    return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
}

// Carpeta donde SUBIR: dir_absoluta opcional; si no, sitio api + dir del repo
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

    return sgi_ruta_fisica_relativa_api($clave);
}

// Rutas en disco donde LEER (escritura + DOCUMENT_ROOT por si difiere del árbol api)
function sgi_rutas_fisicas_candidatas(string $clave): array
{
    $rutas = sgi_rutas_archivos();
    if (!isset($rutas[$clave]['dir'])) {
        return [];
    }

    $candidatas = [sgi_ruta_fisica_escritura($clave)];

    $abs = $rutas[$clave]['dir_absoluta'] ?? null;
    if (is_string($abs) && trim($abs) !== '') {
        $candidatas[] = rtrim(trim($abs), '/\\');
    }

    $doc = sgi_ruta_fisica($clave);
    $candidatas[] = $doc;

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

// Carpeta del repo para escritura (la crea el backend al subir si no existe)
function sgi_asegurar_directorio(string $clave): string
{
    $dir = sgi_ruta_fisica_escritura($clave);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("No se pudo preparar el repositorio: {$dir}");
    }
    return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
}
