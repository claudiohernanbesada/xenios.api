<?php
/**
 * Copiar como rutas_archivos.php en api.raei.com.ar.
 * Todas las URLs: HTTPS y host api.raei.com.ar únicamente.
 */
declare(strict_types=1);

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
