<?php

declare(strict_types=1);

// Config central de la API móvil — acá se arma el array que usa el resto
require_once dirname(__DIR__) . '/_inc/credenciales.php';
require_once dirname(__DIR__) . '/_inc/rutas_archivos.php';

return [
    // Datos de conexión MySQL (vienen de credenciales.php)
    'db' => [
        'host' => $servidor,
        'name' => $baseDeDatos,
        'user' => $usuario,
        'pass' => $contrasena,
        'charset' => 'utf8mb4',
    ],
    // Secret del JWT — cambiarlo en prod, obvio
    'jwt_secret' => 'sgi-movil-dev-cambiar-en-produccion-2026',
    // Token válido una semana
    'jwt_ttl_seconds' => 60 * 60 * 24 * 7,
    // Carpetas y URLs de avatares, fotos de personal, PDFs de salida, etc.
    'archivos' => sgi_rutas_archivos(),
    // Prefijo de URL donde cuelga esta API bajo Apache
    'api_base_path' => '/sgi-movil',
    // Origen permitido en CORS (* en dev, restringir en prod)
    'cors_origin' => '*',
];
