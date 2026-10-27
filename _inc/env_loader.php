<?php
declare(strict_types=1);

function xenios_env_path(): string
{
    static $path = null;
    if ($path !== null) {
        return $path;
    }

    $configuredPath = getenv('XENIOS_MOVIL_ENV_FILE');
    if ($configuredPath !== false && trim($configuredPath) !== '') {
        return $path = trim($configuredPath);
    }

    // El servidor nunca busca secretos dentro del directorio publicado.
    return $path = PHP_OS_FAMILY === 'Windows'
        ? dirname(__DIR__) . '/.env'
        : '/etc/xenios/api/.env';
}

function xenios_cargar_env(?string $path = null): void
{
    static $loaded = [];

    $path ??= xenios_env_path();
    $realPath = realpath($path);

    if ($realPath === false || isset($loaded[$realPath]) || !is_readable($realPath)) {
        return;
    }

    $loaded[$realPath] = true;
    $lines = file($realPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $separatorPosition = strpos($line, '=');
        if ($separatorPosition === false) {
            continue;
        }

        $key = trim(substr($line, 0, $separatorPosition));
        $value = trim(substr($line, $separatorPosition + 1));

        if ($key === '' || getenv($key) !== false) {
            continue;
        }

        $first = $value[0] ?? '';
        $last = $value !== '' ? $value[strlen($value) - 1] : '';
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

xenios_cargar_env();
