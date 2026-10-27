<?php

declare(strict_types=1);

// PDF autorización de salida — lectura en dir_absoluta apiReg (ej. /var/www/vhost/apiReg/www/authsalida/)
require_once dirname(__DIR__, 2) . '/_inc/stream_archivo.php';

const XENIOS_REPO_AUTH_SALIDA = 'auth_salida';

function stream_auth_salida_file(string $authFile, array $config): bool
{
    return xenios_stream_archivo(XENIOS_REPO_AUTH_SALIDA, $authFile, [
        'disposition' => 'attachment',
        'validar' => static fn (string $b): bool => xenios_archivo_es_pdf($b) || xenios_archivo_es_imagen($b),
    ]);
}
