<?php
declare(strict_types=1);

// El secreto TOTP solo se descifra en SGI .NET, nunca en PHP.
function doble_factor_error(array $result, string $fallback): array
{
    $body = ['message' => $result['data']['message'] ?? $fallback];
    if ($result['status'] === 429 && is_array($result['data']['bloqueo'] ?? null)) {
        $body['bloqueo'] = $result['data']['bloqueo'];
    }
    return $body;
}

function doble_factor_config(array $config): array
{
    $options = $config['doble_factor'] ?? [];
    $base = rtrim((string) ($options['base_url'] ?? 'http://127.0.0.1:5090'), '/');
    $key = (string) ($options['bridge_token'] ?? '');
    $uri = parse_url($base);
    if (strlen($key) < 32 || !is_array($uri)
        || !in_array($uri['scheme'] ?? '', ['http', 'https'], true)
        || !in_array($uri['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true)
        || isset($uri['user']) || isset($uri['pass']) || isset($uri['query']) || isset($uri['fragment'])
        || !empty($uri['path'])) {
        throw new RuntimeException('Configurar el puente privado SGI_2FA de la API.');
    }
    return [$base, $key];
}

function doble_factor_solicitar(array $config, string $accion, array $datos): array
{
    [$base, $key] = doble_factor_config($config);
    $curl = curl_init($base . '/internal/auth/doble-factor/' . $accion);
    if ($curl === false) throw new RuntimeException('No se pudo iniciar el verificador de doble factor.');
    curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-SGI-2FA-Key: ' . $key],
        CURLOPT_POSTFIELDS => json_encode($datos, JSON_THROW_ON_ERROR)]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($body) || $status < 100 || $status >= 500) {
        throw new RuntimeException('El verificador de doble factor no está disponible.');
    }
    $data = json_decode($body, true);
    return ['status' => $status, 'data' => is_array($data) ? $data : []];
}

function doble_factor_firma(string $key, string $canal, string $id, string $timestamp, string $version): string
{
    return hash_hmac('sha256', $canal . '|' . strtolower($id) . '|' . $timestamp . '|' . strtolower($version), $key);
}

function doble_factor_token_valido(array $user, array $payload): bool
{
    if (!(bool) ($user['RequiereDobleFactor'] ?? false)) return true;
    return (bool) ($user['DobleFactorConfigurado'] ?? false) && ($payload['mfa'] ?? false) === true
        && is_string($payload['mfaVersion'] ?? null)
        && hash_equals(strtolower((string) ($user['DobleFactorVersion'] ?? '')), strtolower($payload['mfaVersion']));
}

function doble_factor_prueba_valida(array $config, array $proof): bool
{
    [, $key] = doble_factor_config($config);
    $ts = (string) ($proof['timestamp'] ?? '');
    $id = (string) ($proof['usuarioId'] ?? '');
    $version = (string) ($proof['version'] ?? '');
    return $id !== '' && $version !== '' && ctype_digit($ts) && abs(time() - (int) $ts) <= 60
        && hash_equals(doble_factor_firma($key, 'movil', $id, $ts, $version), (string) ($proof['firma'] ?? ''));
}

function doble_factor_puente_web_valido(array $config, array $user, string $timestamp): bool
{
    if (!(bool) ($user['RequiereDobleFactor'] ?? false)) return true;
    [, $key] = doble_factor_config($config);
    $version = (string) ($_SERVER['HTTP_X_SGI_WEB_MFA_VERSION'] ?? '');
    $signature = (string) ($_SERVER['HTTP_X_SGI_WEB_MFA_SIGNATURE'] ?? '');
    return doble_factor_token_valido($user, ['mfa' => true, 'mfaVersion' => $version])
        && hash_equals(doble_factor_firma($key, 'web', (string) $user['Id'], $timestamp, $version), $signature);
}
