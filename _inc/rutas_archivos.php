<?php
// Mapa de carpetas en disco y URLs públicas para avatares, fotos y PDFs de salida
declare(strict_types=1);

/** @var array<string, array{dir: string, url: string, url_respaldo?: list<string>}> */
$GLOBALS['SGI_RUTAS_ARCHIVOS'] = [
    // Avatares sueltos de Usuarios.ImgPerfil
    'avatar_usuario' => [
        'dir' => '/avatar/',
        'url' => 'http://api.raei.com.ar/avatar',
        'url_respaldo' => [
            'http://avatar.raei.com.ar',
        ],
    ],
    // Fotos de legajo en Personal.Foto
    'foto_personal' => [
        'dir' => '/personal/',
        'url' => 'http://api.raei.com.ar/personal',
        'url_respaldo' => [
            'http://personal.raei.com.ar',
        ],
    ],
    // Adjuntos de AuthSalida.AuthFile
    'auth_salida' => [
        'dir' => '/authsalida/',
        'url' => 'http://api.raei.com.ar/authsalida',
        'url_respaldo' => [
            'http://authsalida.raei.com.ar',
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

// Path completo en disco si el archivo existe localmente; si no, null
function sgi_path_archivo_local(string $clave, string $nombreArchivo): ?string
{
    $name = basename($nombreArchivo);
    if ($name === '') {
        return null;
    }
    $path = sgi_ruta_fisica($clave) . $name;
    return is_file($path) ? $path : null;
}

// Crea la carpeta del repo si todavía no existe
function sgi_asegurar_directorio(string $clave): string
{
    $dir = sgi_ruta_fisica($clave);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException("No se pudo crear el directorio: {$dir}");
    }
    return $dir;
}
