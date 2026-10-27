<?php
declare(strict_types=1);

// Helpers de respuesta HTTP: CORS + JSON
// Headers CORS para que la app Expo/React Native pueda pegarle desde el browser
function apply_cors(array $config): void
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $allowed = $config['cors_origins'] ?? [];
    header('Vary: Origin', false);
    if ($origin === '' || !in_array($origin, $allowed, true)) {
        return;
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Auth-Token, X-API-KEY, Accept');
    header('Access-Control-Max-Age: 600');
}

// Respuesta JSON estándar con código HTTP y CORS aplicado
function json_response(array $data, int $code = 200, ?array $config = null): void
{
    if ($config !== null) {
        apply_cors($config);
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}
