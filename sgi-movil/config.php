<?php

declare(strict_types=1);

// Config central de la API móvil — acá se arma el array que usa el resto
require_once dirname(__DIR__) . '/_inc/credenciales.php';
require_once dirname(__DIR__) . '/_inc/rutas_archivos.php';
require_once dirname(__DIR__) . '/_inc/api_tokens.php';

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
    'api_key' => sgi_api_token_esperado(),
    'app_version' => [
        'android_latest_version' => getenv('SGI_MOVIL_ANDROID_LATEST_VERSION') ?: '',
        'android_store_url' => getenv('SGI_MOVIL_ANDROID_STORE_URL') ?: 'https://play.google.com/store/apps/details?id=ar.com.raei.sgi',
        'ios_latest_version' => getenv('SGI_MOVIL_IOS_LATEST_VERSION') ?: '',
        'ios_store_url' => getenv('SGI_MOVIL_IOS_STORE_URL') ?: '',
    ],
    'push' => [
        'fcm' => [
            'service_account_file' => getenv('GOOGLE_APPLICATION_CREDENTIALS')
                ?: (getenv('SGI_FCM_SERVICE_ACCOUNT_FILE') ?: ''),
            'project_id' => getenv('SGI_FCM_PROJECT_ID') ?: '',
        ],
        'apns' => [
            'key_file' => getenv('SGI_APNS_KEY_FILE') ?: '',
            'key_id' => getenv('SGI_APNS_KEY_ID') ?: '',
            'team_id' => getenv('SGI_APNS_TEAM_ID') ?: '',
            'topic' => getenv('SGI_APNS_TOPIC') ?: 'ar.com.raei.sgi',
        ],
    ],
    'password_reset' => [
        'public_base_url' => getenv('SGI_PASSWORD_RESET_URL') ?: 'https://restartpassword.sgi.raei.com.ar',
        'ttl_seconds' => 3600,
        'mail_from' => getenv('SGI_MAIL_FROM') ?: 'noreply@raei.com.ar',
        'mail_from_name' => 'SGI - Metalúrgica RAEI',
    ],
];
