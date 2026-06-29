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
    // Clave única para endpoints de administración / sistemas externos (header X-API-KEY)
    'api_key' => getenv('SGI_API_KEY') ?: getenv('SGI_NOVEDADES_API_KEY') ?: 'c0256d3b-8510-4a7f-af05-92b007521f23',
    'password_reset' => [
        'public_base_url' => getenv('SGI_PASSWORD_RESET_URL') ?: 'https://restartpassword.sgi.raei.com.ar',
        'api_public_url' => getenv('SGI_PASSWORD_RESET_API_URL') ?: 'https://api.raei.com.ar/sgi-movil',
        'ttl_seconds' => 3600,
        'mail_from' => getenv('SGI_MAIL_FROM') ?: 'noreply@raei.com.ar',
        'mail_from_name' => 'SGI - Metalúrgica RAEI',
    ],
    'app_version' => [
        'latest_version' => getenv('SGI_MOVIL_LATEST_VERSION') ?: '1.0.6.5',
        'minimum_version' => getenv('SGI_MOVIL_MINIMUM_VERSION') ?: '1.0.6.5',
        'android_version_code' => (int) (getenv('SGI_MOVIL_ANDROID_VERSION_CODE') ?: 8),
        'download_url' => getenv('SGI_MOVIL_DOWNLOAD_URL') ?: '',
        'message' => getenv('SGI_MOVIL_UPDATE_MESSAGE') ?: 'Hay una nueva version disponible de SGI Movil.',
    ],
];
