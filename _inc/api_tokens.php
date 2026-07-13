<?php
declare(strict_types=1);

require_once __DIR__ . '/env_loader.php';

function sgi_api_token_esperado(): string
{
    $envToken = getenv('SGI_API_TOKEN');
    return is_string($envToken) ? trim($envToken) : '';
}

function sgi_api_token_recibido(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $apiKey = $headers['X-API-KEY'] ?? $headers['X-Api-Key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';

    if (is_string($apiKey) && $apiKey !== '') {
        return trim($apiKey);
    }

    $authorization = $headers['Authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (is_string($authorization) && stripos($authorization, 'Bearer ') === 0) {
        return trim(substr($authorization, 7));
    }

    return trim((string) ($_POST['api_key'] ?? $_GET['api_key'] ?? ''));
}

function sgi_validar_api_token(): bool
{
    $esperado = sgi_api_token_esperado();
    $recibido = sgi_api_token_recibido();

    return $esperado !== '' && $recibido !== '' && hash_equals($esperado, $recibido);
}
