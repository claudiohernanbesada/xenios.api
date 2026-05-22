<?php
declare(strict_types=1);

// Headers CORS para que la app Expo/React Native pueda pegarle desde el browser
function apply_cors(array $config): void
{
    $origin = $config['cors_origin'] ?? '*';
    if ($origin === '*' && !empty($_SERVER['HTTP_ORIGIN'])) {
        // En dev web usamos el origen real del bundler (localhost:8081 y similares)
        $origin = $_SERVER['HTTP_ORIGIN'];
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Auth-Token, Accept');
    header('Access-Control-Max-Age: 86400');
    if ($origin !== '*') {
        header('Access-Control-Allow-Credentials: true');
    }
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
