<?php
/**
 * Copiar como rutas_archivos.php y ajustar URLs al publicar.
 *
 * Desarrollo WAMP (IP local):
 *   'url' => 'http://10.30.1.21/avatar',
 *
 * Producción:
 *   'url' => 'https://api.raei.com.ar/avatar',
 */
declare(strict_types=1);

$GLOBALS['SGI_RUTAS_ARCHIVOS'] = [
    'avatar_usuario' => [
        'dir' => '/var/www/vhost/apiReg/www/avatar/',
        'url' => 'http://api.raei.com.ar/avatar',
        'url_respaldo' => ['http://avatar.raei.com.ar'],
    ],
    'foto_personal' => [
        'dir' => '/var/www/vhost/apiReg/www/personal/',
        'url' => 'http://api.raei.com.ar/personal',
        'url_respaldo' => ['http://personal.raei.com.ar'],
    ],
    'auth_salida' => [
        'dir' => '/var/www/vhost/apiReg/www/authsalida/',
        'url' => 'http://api.raei.com.ar/authsalida',
        'url_respaldo' => ['http://authsalida.raei.com.ar'],
    ],
];
