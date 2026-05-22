<?php
/**
 * Copiar como rutas_archivos.php en el servidor api.raei.com.ar.
 * Ajustar solo si las URLs públicas o rutas en disco difieren en otro entorno.
 */
declare(strict_types=1);

$GLOBALS['SGI_RUTAS_ARCHIVOS'] = [
    'avatar_usuario' => [
        'dir' => '/avatar/',
        'url' => 'https://api.raei.com.ar/avatar',
        'url_respaldo' => ['https://avatar.raei.com.ar'],
    ],
    'foto_personal' => [
        'dir' => '/personal/',
        'url' => 'https://api.raei.com.ar/personal',
        'url_respaldo' => ['https://personal.raei.com.ar'],
    ],
    'auth_salida' => [
        'dir' => '/authsalida/',
        'url' => 'https://api.raei.com.ar/authsalida',
        'url_respaldo' => ['https://authsalida.raei.com.ar'],
    ],
];
