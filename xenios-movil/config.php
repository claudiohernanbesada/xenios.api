<?php

declare(strict_types=1);

// Config central de la API móvil — acá se arma el array que usa el resto
require_once dirname(__DIR__) . '/_inc/rutas_archivos.php';
require_once dirname(__DIR__) . '/_inc/api_tokens.php';

date_default_timezone_set('America/Argentina/Buenos_Aires');

if (!function_exists('xenios_movil_first_existing_file')) {
    function xenios_movil_first_existing_file(array $paths): string
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

if (!function_exists('xenios_movil_dotenv_value')) {
    function xenios_movil_dotenv_value(string $key, array $paths): string
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

if (!function_exists('xenios_movil_env_int')) {
    function xenios_movil_env_int(string $key, int $default): int
    {
        $value = getenv($key);
        if ($value === false || trim((string) $value) === '') {
            return $default;
        }

        return (int) $value;
    }
}

$xeniosMovilEnvPaths = [
    xenios_env_path(),
];

if (!function_exists('xenios_movil_env_value')) {
    function xenios_movil_env_value(string $key, array $paths, string $default = ''): string
    {
        $value = getenv($key);
        if ($value !== false && trim((string) $value) !== '') {
            return trim((string) $value);
        }

        $dotenvValue = xenios_movil_dotenv_value($key, $paths);
        return $dotenvValue !== '' ? $dotenvValue : $default;
    }
}

$publishedAppVersion = xenios_movil_env_value('EXPO_PUBLIC_APP_VERSION', $xeniosMovilEnvPaths, '1.0.0');

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
    // Clave privada del servidor; no incluir en la app ni usar un valor fijo de desarrollo.
    'jwt_secret' => xenios_movil_env_value('XENIOS_JWT_SECRET', $xeniosMovilEnvPaths),
    // Sesión válida durante una hora.
    'jwt_ttl_seconds' => 60 * 60,
    // Carpetas y URLs de avatares, fotos de personal, PDFs de salida, etc.
    'archivos' => xenios_rutas_archivos(),
    // Prefijo de URL donde cuelga esta API bajo Apache
    'api_base_path' => '/xenios-movil',
    'doble_factor' => [
        'base_url' => xenios_movil_env_value('XENIOS_2FA_BASE_URL', $xeniosMovilEnvPaths, 'http://127.0.0.1:5090'),
        'bridge_token' => xenios_movil_env_value('XENIOS_2FA_BRIDGE_TOKEN', $xeniosMovilEnvPaths),
    ],
    // Orígenes web exactos permitidos en CORS.
    'cors_origins' => array_filter(array_map('trim', explode(',', xenios_movil_env_value('XENIOS_CORS_ORIGINS', $xeniosMovilEnvPaths, 'https://app.xenios.com.ar,https://xenios.com.ar,https://www.xenios.com.ar')))),
    // Clave REST; las operaciones privilegiadas agregan X-Service-Key.
    'api_key' => xenios_api_token_esperado(),
    'hotel_mail' => ['service_key' => xenios_movil_env_value('XENIOS_SERVICE_TOKEN', $xeniosMovilEnvPaths)],
    'web_bridge_secret' => xenios_movil_env_value('XENIOS_WEB_BRIDGE_SECRET', $xeniosMovilEnvPaths),
    'xenios_web_base_url' => rtrim(xenios_movil_env_value('XENIOS_WEB_BASE_URL', $xeniosMovilEnvPaths, 'https://app.xenios.com.ar'), '/'),
    'app_version' => [
        'android_latest_version' => $publishedAppVersion,
        'android_store_url' => xenios_movil_env_value('XENIOS_MOVIL_ANDROID_STORE_URL', $xeniosMovilEnvPaths, 'https://play.google.com/store/apps/details?id=ar.com.xenios.app'),
        'ios_latest_version' => $publishedAppVersion,
        'ios_store_url' => xenios_movil_env_value('XENIOS_MOVIL_IOS_STORE_URL', $xeniosMovilEnvPaths),
    ],
    'asistencia_calculo' => [
        'max_duracion_jornada_horas' => xenios_movil_env_int('ASISTENCIA_CALCULO_MAX_DURACION_JORNADA_HORAS', 13),
        'descanso_minimo_horas' => xenios_movil_env_int('ASISTENCIA_CALCULO_DESCANSO_MINIMO_HORAS', 8),
        'fraccionamiento_minutos' => xenios_movil_env_int('ASISTENCIA_CALCULO_FRACCIONAMIENTO_MINUTOS', 60),
        'deduplicacion_minutos' => xenios_movil_env_int('ASISTENCIA_CALCULO_DEDUPLICACION_MINUTOS', 2),
        'jornada_vigilancia_horas' => xenios_movil_env_int('ASISTENCIA_CALCULO_JORNADA_VIGILANCIA', 12),
        'maximo_horas_extras_dia' => xenios_movil_env_int('MAXIMO_HORAS_EXTRAS_DIA', 3),
    ],
    'push' => [
        'fcm' => [
            'service_account_file' => xenios_movil_first_existing_file([
                xenios_movil_env_value('GOOGLE_APPLICATION_CREDENTIALS', $xeniosMovilEnvPaths),
                xenios_movil_env_value('XENIOS_FCM_SERVICE_ACCOUNT_FILE', $xeniosMovilEnvPaths),
                '/etc/xenios/firebase-service-account.json',
                dirname(__DIR__) . '/firebase-service-account.json',
                dirname(__DIR__) . '/_private/firebase-service-account.json',
            ]),
            'project_id' => xenios_movil_env_value('XENIOS_FCM_PROJECT_ID', $xeniosMovilEnvPaths),
        ],
        'apns' => [
            'key_file' => getenv('XENIOS_APNS_KEY_FILE') ?: '',
            'key_id' => getenv('XENIOS_APNS_KEY_ID') ?: '',
            'team_id' => getenv('XENIOS_APNS_TEAM_ID') ?: '',
            'topic' => getenv('XENIOS_APNS_TOPIC') ?: 'ar.com.xenios.app',
        ],
    ],
    'password_reset' => [
        'public_base_url' => xenios_movil_env_value('XENIOS_PASSWORD_RESET_URL', $xeniosMovilEnvPaths, 'https://api.xenios.com.ar/xenios-movil/reset-password'),
        'ttl_seconds' => 60 * 30,
        'mail_from' => xenios_movil_env_value('XENIOS_MAIL_FROM', $xeniosMovilEnvPaths, 'norepply@xenios.com.ar'),
        'mail_from_name' => xenios_movil_env_value('XENIOS_MAIL_DISPLAY_NAME', $xeniosMovilEnvPaths, 'No Reply - Xenios'),
        'mail_logo_path' => xenios_movil_first_existing_file([
            xenios_movil_env_value('XENIOS_MAIL_LOGO_PATH', $xeniosMovilEnvPaths),
            __DIR__ . '/assets/xenios-logo.png',
        ]),
        'app_login_url' => xenios_movil_env_value('XENIOS_APP_LOGIN_URL', $xeniosMovilEnvPaths, 'xenios://login'),
        'smtp' => [
            'host' => xenios_movil_env_value('XENIOS_SMTP_HOST', $xeniosMovilEnvPaths, 'smtp.office365.com'),
            'port' => (int) xenios_movil_env_value('XENIOS_SMTP_PORT', $xeniosMovilEnvPaths, '587'),
            'username' => xenios_movil_env_value('XENIOS_SMTP_USERNAME', $xeniosMovilEnvPaths, 'norepply@xenios.com.ar'),
            'password' => xenios_movil_env_value('XENIOS_SMTP_PASSWORD', $xeniosMovilEnvPaths),
            'ssl' => filter_var(xenios_movil_env_value('XENIOS_SMTP_SSL', $xeniosMovilEnvPaths, 'true'), FILTER_VALIDATE_BOOLEAN),
            'encryption' => xenios_movil_env_value('XENIOS_SMTP_ENCRYPTION', $xeniosMovilEnvPaths, 'tls'),
            'timeout_seconds' => (int) xenios_movil_env_value('XENIOS_SMTP_TIMEOUT_SECONDS', $xeniosMovilEnvPaths, '20'),
        ],
    ],
];
