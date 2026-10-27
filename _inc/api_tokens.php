<?php
declare(strict_types=1);

require_once __DIR__ . '/env_loader.php';

function xenios_api_token_esperado(): string
{
    $envToken = getenv('XENIOS_API_TOKEN');
    $token = is_string($envToken) ? trim($envToken) : '';
    return strlen($token) >= 32 ? $token : '';
}

function xenios_api_token_recibido(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $apiKey = $headers['X-API-KEY'] ?? $headers['X-Api-Key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';

    if (is_string($apiKey) && $apiKey !== '') {
        return trim($apiKey);
    }

    return '';
}

function xenios_validar_api_token(): bool
{
    $esperado = xenios_api_token_esperado();
    $recibido = xenios_api_token_recibido();

    return $esperado !== '' && $recibido !== '' && hash_equals($esperado, $recibido);
}
