<?php
// Mapa de carpetas en disco y URLs públicas — HTTPS en api.raei.com.ar/{repo}
declare(strict_types=1);

/** @var array<string, array{dir: string, dir_absoluta?: string, url: string, url_respaldo?: list<string>}> */
$GLOBALS['SGI_RUTAS_ARCHIVOS'] = [
    'avatar_usuario' => [
        'dir' => '/avatar/',
        'url' => 'https://api.raei.com.ar/avatar',
    ],
    'foto_personal' => [
        'dir' => '/personal/',
        'url' => 'https://api.raei.com.ar/personal',
    ],
    'auth_salida' => [
        'dir' => '/authsalida/',
        'url' => 'https://api.raei.com.ar/authsalida',
    ],
];

function sgi_rutas_archivos(): array
{
    return $GLOBALS['SGI_RUTAS_ARCHIVOS'];
}

function sgi_api_site_root(): string
{
    return dirname(__DIR__);
}

function sgi_document_root(): string
{
    return rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
}

function sgi_url_es_sitio_api(string $baseUrl): bool
{
    $host = parse_url($baseUrl, PHP_URL_HOST);
    return is_string($host) && strcasecmp($host, 'api.raei.com.ar') === 0;
}

function sgi_url_usa_https(string $baseUrl): bool
{
    $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
    return is_string($scheme) && strcasecmp($scheme, 'https') === 0;
}

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

function sgi_ruta_url(string $clave): string
{
    $rutas = sgi_rutas_archivos();
    if (!isset($rutas[$clave]['url'])) {
        throw new InvalidArgumentException("URL de repositorio desconocida: {$clave}");
    }
    $url = rtrim((string) $rutas[$clave]['url'], '/');
    if (!sgi_url_es_sitio_api($url) || !sgi_url_usa_https($url)) {
        throw new InvalidArgumentException("La URL del repo debe ser https://api.raei.com.ar: {$url}");
    }
    return $url;
}

function sgi_rutas_respaldo(string $clave): array
{
    $rutas = sgi_rutas_archivos();
    $bases = $rutas[$clave]['url_respaldo'] ?? [];
    if (!is_array($bases)) {
        return [];
    }
    $out = [];
    foreach ($bases as $base) {
        if (!is_string($base) || trim($base) === '') {
            continue;
        }
        $normalized = rtrim(trim($base), '/');
        if (sgi_url_es_sitio_api($normalized) && sgi_url_usa_https($normalized)) {
            $out[] = $normalized;
        }
    }
    return array_values(array_unique($out));
}

function sgi_url_archivo(string $clave, string $nombreArchivo): string
{
    return sgi_ruta_url($clave) . '/' . rawurlencode(basename($nombreArchivo));
}

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

    $candidatas[] = sgi_ruta_fisica($clave);

    return array_values(array_unique($candidatas));
}

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

function sgi_asegurar_directorio(string $clave): string
{
    $dir = sgi_ruta_fisica_escritura($clave);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("No se pudo preparar el repositorio: {$dir}");
    }
    return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
}
