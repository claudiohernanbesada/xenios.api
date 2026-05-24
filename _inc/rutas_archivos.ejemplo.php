<?php
/**
 * Copiar como rutas_archivos.php en el servidor.
 * Avatares en producción: /var/www/vhost/apiReg/www/avatar/
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
        'url' => 'https://api.raei.com.ar/personal',
    ],
    'auth_salida' => [
        'dir' => '/authsalida/',
        'url' => 'https://api.raei.com.ar/authsalida',
    ],
];
