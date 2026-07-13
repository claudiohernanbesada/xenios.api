<?php
declare(strict_types=1);

function sgi_cargar_env(?string $path = null): void
{
    static $loaded = [];

    $path ??= dirname(__DIR__) . '/.env';
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

        if ($key === '') {
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

sgi_cargar_env();
