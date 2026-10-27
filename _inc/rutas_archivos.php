<?php

// Mapa de carpetas en disco y URLs públicas — HTTPS en api.xenios.com.ar/{repo}

declare(strict_types=1);
require_once __DIR__ . '/env_loader.php';



/**

 * Producción: archivos bajo apiReg, no bajo el vhost apixenios donde vive xenios-movil.

 * Variables de entorno opcionales: XENIOS_AVATAR_DIR, XENIOS_AUTHSALIDA_DIR, XENIOS_PERSONAL_DIR

 */

function xenios_dir_absoluta_api_reg(string $carpeta, ?string $envVar = null): ?string

{

    if ($envVar !== null) {

        $fromEnv = getenv($envVar);

        if (is_string($fromEnv) && trim($fromEnv) !== '') {

            return rtrim(trim($fromEnv), '/');

        }

    }

    $prod = '/var/lib/xenios-api/' . trim($carpeta, '/');

    if (is_dir($prod)) {

        return $prod;

    }



    return null;

}



$xeniosAvatarDirAbsoluta = xenios_dir_absoluta_api_reg('avatar', 'XENIOS_AVATAR_DIR');

$xeniosAuthsalidaDirAbsoluta = xenios_dir_absoluta_api_reg('authsalida', 'XENIOS_AUTHSALIDA_DIR');

$xeniosPersonalDirAbsoluta = xenios_dir_absoluta_api_reg('personal', 'XENIOS_PERSONAL_DIR');



/** @var array<string, array{dir: string, dir_absoluta?: string|null, url: string, url_respaldo?: list<string>}> */

$GLOBALS['XENIOS_RUTAS_ARCHIVOS'] = [

    'avatar_usuario' => [

        'dir' => '/avatar/',

        'dir_absoluta' => $xeniosAvatarDirAbsoluta,

        'url' => 'https://api.xenios.com.ar/avatar',

    ],

    'foto_personal' => [

        'dir' => '/personal/',

        'dir_absoluta' => $xeniosPersonalDirAbsoluta,

        'url' => 'https://api.xenios.com.ar/personal',

    ],

    'auth_salida' => [

        'dir' => '/authsalida/',

        'dir_absoluta' => $xeniosAuthsalidaDirAbsoluta,

        'url' => 'https://api.xenios.com.ar/authsalida',

    ],

];



function xenios_rutas_archivos(): array

{

    return $GLOBALS['XENIOS_RUTAS_ARCHIVOS'];

}



function xenios_api_site_root(): string

{

    return dirname(__DIR__);

}



function xenios_document_root(): string

{

    return rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');

}



function xenios_url_es_sitio_api(string $baseUrl): bool

{

    $host = parse_url($baseUrl, PHP_URL_HOST);

    return is_string($host) && strcasecmp($host, 'api.xenios.com.ar') === 0;

}



function xenios_url_usa_https(string $baseUrl): bool

{

    $scheme = parse_url($baseUrl, PHP_URL_SCHEME);

    return is_string($scheme) && strcasecmp($scheme, 'https') === 0;

}



function xenios_ruta_fisica(string $clave): string

{

    $rutas = xenios_rutas_archivos();

    if (!isset($rutas[$clave]['dir'])) {

        throw new InvalidArgumentException("Repositorio de archivos desconocido: {$clave}");

    }

    $rel = $rutas[$clave]['dir'];

    if ($rel[0] !== '/') {

        $rel = '/' . $rel;

    }

    return xenios_document_root() . $rel;

}



function xenios_ruta_url(string $clave): string

{

    $rutas = xenios_rutas_archivos();

    if (!isset($rutas[$clave]['url'])) {

        throw new InvalidArgumentException("URL de repositorio desconocida: {$clave}");

    }

    $url = rtrim((string) $rutas[$clave]['url'], '/');

    if (!xenios_url_es_sitio_api($url) || !xenios_url_usa_https($url)) {

        throw new InvalidArgumentException("La URL del repo debe ser https://api.xenios.com.ar: {$url}");

    }

    return $url;

}



function xenios_rutas_respaldo(string $clave): array

{

    $rutas = xenios_rutas_archivos();

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

        if (xenios_url_es_sitio_api($normalized) && xenios_url_usa_https($normalized)) {

            $out[] = $normalized;

        }

    }

    return array_values(array_unique($out));

}



function xenios_url_archivo(string $clave, string $nombreArchivo): string

{

    return xenios_ruta_url($clave) . '/' . rawurlencode(basename($nombreArchivo));

}



function xenios_ruta_fisica_relativa_api(string $clave): string

{

    $rutas = xenios_rutas_archivos();

    $rel = ltrim((string) ($rutas[$clave]['dir'] ?? ''), '/\\');

    $root = xenios_api_site_root();

    if ($rel === '' || $rel === '/') {

        return rtrim($root, '/\\');

    }



    return $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

}



function xenios_ruta_fisica_escritura(string $clave): string

{

    $rutas = xenios_rutas_archivos();

    if (!isset($rutas[$clave]['dir'])) {

        throw new InvalidArgumentException("Repositorio de archivos desconocido: {$clave}");

    }



    $abs = $rutas[$clave]['dir_absoluta'] ?? null;

    if (is_string($abs) && trim($abs) !== '') {

        return rtrim(trim($abs), '/\\');

    }



    return xenios_ruta_fisica_relativa_api($clave);

}



/** Carpetas legacy bajo el vhost apixenios (subidas antiguas). */

function xenios_ruta_legacy_api_xenios(string $clave): ?string

{

    $rel = match ($clave) {

        'avatar_usuario' => 'avatar',

        'auth_salida' => 'authsalida',

        'foto_personal' => 'personal',

        default => null,

    };

    if ($rel === null) {

        return null;

    }



    return xenios_api_site_root() . DIRECTORY_SEPARATOR . $rel;

}



function xenios_rutas_fisicas_candidatas(string $clave): array

{

    $rutas = xenios_rutas_archivos();

    if (!isset($rutas[$clave]['dir'])) {

        return [];

    }



    $candidatas = [];



    $abs = $rutas[$clave]['dir_absoluta'] ?? null;

    if (is_string($abs) && trim($abs) !== '') {

        $candidatas[] = rtrim(trim($abs), '/\\');

    }



    $candidatas[] = xenios_ruta_fisica_escritura($clave);

    $candidatas[] = xenios_ruta_fisica($clave);



    $legacy = xenios_ruta_legacy_api_xenios($clave);

    if ($legacy !== null) {

        $candidatas[] = $legacy;

    }



    return array_values(array_unique($candidatas));

}



function xenios_path_archivo_local(string $clave, string $nombreArchivo): ?string

{

    $name = basename($nombreArchivo);

    if ($name === '') {

        return null;

    }

    foreach (xenios_rutas_fisicas_candidatas($clave) as $dir) {

        $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;

        if (is_file($path)) {

            return $path;

        }

    }

    return null;

}



function xenios_asegurar_directorio(string $clave): string

{

    $dir = xenios_ruta_fisica_escritura($clave);

    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {

        throw new RuntimeException(

            'No se pudo crear el directorio del repositorio: ' . $dir

            . '. Verifique permisos de escritura para el usuario de Apache/PHP.'

        );

    }

    if (!is_writable($dir)) {

        throw new RuntimeException(

            'El directorio del repositorio no tiene permiso de escritura: ' . $dir

            . '. En el servidor: chown/chmod para el usuario de PHP.'

        );

    }

    return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;

}

