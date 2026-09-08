<?php

declare(strict_types=1);

// Config central de la API móvil — acá se arma el array que usa el resto
require_once dirname(__DIR__) . '/_inc/rutas_archivos.php';
require_once dirname(__DIR__) . '/_inc/api_tokens.php';

date_default_timezone_set('America/Argentina/Buenos_Aires');

if (!function_exists('sgi_movil_first_existing_file')) {
    function sgi_movil_first_existing_file(array $paths): string
    {
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        return '';
    }
}

if (!function_exists('sgi_movil_dotenv_value')) {
    function sgi_movil_dotenv_value(string $key, array $paths): string
    {
        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '' || !is_file($path) || !is_readable($path)) {
                continue;
            }

            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines === false) {
                continue;
            }

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }

                [$name, $value] = array_map('trim', explode('=', $line, 2));
                if ($name !== $key) {
                    continue;
                }

                $quote = $value[0] ?? '';
                if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
                    $value = substr($value, 1, -1);
                }

                return trim($value);
            }
        }

        return '';
    }
}

if (!function_exists('sgi_movil_env_int')) {
    function sgi_movil_env_int(string $key, int $default): int
    {
        $value = getenv($key);
        if ($value === false || trim((string) $value) === '') {
            return $default;
        }

        return (int) $value;
    }
}

$appVersionFromEnvFile = sgi_movil_dotenv_value('EXPO_PUBLIC_APP_VERSION', [
    getenv('SGI_MOVIL_ENV_FILE') ?: '',
    __DIR__ . '/.env',
    dirname(__DIR__) . '/.env',
    dirname(__DIR__, 2) . '/.env',
    '/var/www/vhost/apiSgiRAEI/www/apiSgiRAEI/.env',
]);
$publishedAppVersion = $appVersionFromEnvFile !== '' ? $appVersionFromEnvFile : '1.0.0';

$dbCredentials = (static function (): array {
    require dirname(__DIR__) . '/_inc/credenciales.php';

    return [
        'host' => $servidor,
        'name' => $baseDeDatos,
        'user' => $usuario,
        'pass' => $contrasena,
    ];
})();

return [
    // Datos de conexión MySQL (vienen de credenciales.php)
    'db' => $dbCredentials + [
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
        'android_latest_version' => $publishedAppVersion,
        'android_store_url' => getenv('SGI_MOVIL_ANDROID_STORE_URL') ?: 'https://play.google.com/store/apps/details?id=ar.com.raei.sgi',
        'ios_latest_version' => $publishedAppVersion,
        'ios_store_url' => getenv('SGI_MOVIL_IOS_STORE_URL') ?: '',
    ],
    'asistencia_calculo' => [
        'max_duracion_jornada_horas' => sgi_movil_env_int('ASISTENCIA_CALCULO_MAX_DURACION_JORNADA_HORAS', 13),
        'descanso_minimo_horas' => sgi_movil_env_int('ASISTENCIA_CALCULO_DESCANSO_MINIMO_HORAS', 8),
        'fraccionamiento_minutos' => sgi_movil_env_int('ASISTENCIA_CALCULO_FRACCIONAMIENTO_MINUTOS', 60),
        'deduplicacion_minutos' => sgi_movil_env_int('ASISTENCIA_CALCULO_DEDUPLICACION_MINUTOS', 2),
        'jornada_vigilancia_horas' => sgi_movil_env_int('ASISTENCIA_CALCULO_JORNADA_VIGILANCIA', 12),
        'maximo_horas_extras_dia' => sgi_movil_env_int('MAXIMO_HORAS_EXTRAS_DIA', 3),
    ],
    'push' => [
        'fcm' => [
            'service_account_file' => sgi_movil_first_existing_file([
                getenv('GOOGLE_APPLICATION_CREDENTIALS') ?: '',
                getenv('SGI_FCM_SERVICE_ACCOUNT_FILE') ?: '',
                '/etc/sgi/firebase-service-account.json',
                dirname(__DIR__) . '/firebase-service-account.json',
                dirname(__DIR__) . '/_private/firebase-service-account.json',
            ]),
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
        'public_base_url' => getenv('SGI_PASSWORD_RESET_URL') ?: 'https://api.raei.com.ar/sgi-movil/reset-password',
        'ttl_seconds' => 60 * 30,
        'mail_from' => getenv('SGI_MAIL_FROM') ?: 'noreply@raei.com.ar',
        'mail_from_name' => 'SGI - Metalúrgica RAEI',
        'app_login_url' => getenv('SGI_APP_LOGIN_URL') ?: 'sgi://login',
    ],
];
