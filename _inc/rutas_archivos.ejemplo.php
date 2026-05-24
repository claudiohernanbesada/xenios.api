<?php
/**
 * Copiar como rutas_archivos.php en el servidor productivo.
 * Disco real bajo apiReg (la API sgi-movil corre en apiSgiRAEI).
 */
declare(strict_types=1);

$GLOBALS['SGI_RUTAS_ARCHIVOS'] = [
    'avatar_usuario' => [
        'dir' => '/avatar/',
        'dir_absoluta' => '/var/www/vhost/apiReg/www/avatar',
        'url' => 'https://api.raei.com.ar/avatar',
    ],
    'foto_personal' => [
        'dir' => '/personal/',
        'dir_absoluta' => '/var/www/vhost/apiReg/www/personal',
        'url' => 'https://api.raei.com.ar/personal',
    ],
    'auth_salida' => [
        'dir' => '/authsalida/',
        'dir_absoluta' => '/var/www/vhost/apiReg/www/authsalida',
        'url' => 'https://api.raei.com.ar/authsalida',
    ],
];
