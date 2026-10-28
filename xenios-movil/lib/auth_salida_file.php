<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/_inc/auth_salida_archivos.php';

const XENIOS_REPO_AUTH_SALIDA = 'auth_salida';

function stream_auth_salida_file(string $authFile, array $config): bool
{
    return xenios_auth_salida_stream($authFile);
}
