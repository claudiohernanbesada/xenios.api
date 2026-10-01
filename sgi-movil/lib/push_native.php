<?php
declare(strict_types=1);

function push_native_config(): array
{
    static $pushConfig = null;
    if (is_array($pushConfig)) {
        return $pushConfig;
    }

    $config = require dirname(__DIR__) . '/config.php';
    $pushConfig = is_array($config['push'] ?? null) ? $config['push'] : [];
    return $pushConfig;
}

function push_native_token_valido(string $token, string $plataforma): bool
{
    $token = trim($token);
    if ($token === '' || strlen($token) > 255 || preg_match('/\s/', $token) === 1) {
        return false;
    }
    if (preg_match('/^Expo(nent)?PushToken\[/', $token) === 1) {
        return false;
    }

    if ($plataforma === 'android') {
        return strlen($token) >= 20;
    }

    if ($plataforma === 'ios-development' || $plataforma === 'ios-production') {
        return preg_match('/^[a-f0-9]{64}$/i', $token) === 1;
    }

    return false;
}

function push_native_ca_bundle(): ?string
{
    $candidates = [
        getenv('CURL_CA_BUNDLE') ?: '',
        getenv('SSL_CERT_FILE') ?: '',
        ini_get('curl.cainfo') ?: '',
        ini_get('openssl.cafile') ?: '',
    ];

    foreach ($candidates as $candidate) {
        $path = trim((string) $candidate);
        if ($path !== '' && is_file($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * @param list<array{token:string, plataforma:string}> $registrations
 * @return list<array<string, mixed>>
 */
function push_native_build_novedad_messages(
    array $registrations,
    string $novedadId,
    string $titulo,
    string $cuerpo
): array {
    $messages = [];
    foreach ($registrations as $registration) {
        $token = trim((string) ($registration['token'] ?? ''));
        $plataforma = (string) ($registration['plataforma'] ?? '');
        if (!push_native_token_valido($token, $plataforma)) {
            continue;
        }

        $messages[] = [
            'token' => $token,
            'plataforma' => $plataforma,
            'title' => $titulo !== '' ? $titulo : 'SGI - RAEI',
            'body' => $cuerpo !== '' ? $cuerpo : 'Tiene novedades pendientes de lectura',
            'channelId' => 'novedades',
            'data' => [
                'novedadId' => $novedadId,
                'screen' => 'novedades',
            ],
        ];
    }

    return $messages;
}

/**
 * @param list<array{token:string, plataforma:string, fecha:string, hora:string}> $items
 * @return list<array<string, mixed>>
 */
function push_native_build_fichada_messages(array $items): array
{
    $messages = [];
    foreach ($items as $item) {
        $token = trim((string) ($item['token'] ?? ''));
        $plataforma = (string) ($item['plataforma'] ?? '');
        if (!push_native_token_valido($token, $plataforma)) {
            continue;
        }

        $fecha = (string) ($item['fecha'] ?? '');
        $hora = (string) ($item['hora'] ?? '');
        $messages[] = [
            'token' => $token,
            'plataforma' => $plataforma,
            'fichadaId' => (int) ($item['fichadaId'] ?? 0),
            'usuarioId' => (string) ($item['usuarioId'] ?? ''),
            'title' => 'SGI - RAEI',
            'body' => "Hemos registrado tu fichada del {$fecha} a las {$hora} hs. con éxito!",
            'channelId' => 'fichadas',
            'data' => [
                'tipo' => 'fichada',
                'screen' => 'fichadas',
                'fichadaId' => (string) ($item['fichadaId'] ?? ''),
            ],
        ];
    }

    return $messages;
}

/**
 * @param list<array<string, mixed>> $messages
 * @return array{ok:bool, sent:int, providerResults:list<array<string, mixed>>, errors:list<string>}
 */
function push_native_send_batch(array $messages): array
{
    if ($messages === []) {
        return ['ok' => true, 'sent' => 0, 'providerResults' => [], 'errors' => []];
    }

    $sent = 0;
    $results = [];
    $errors = [];
    foreach ($messages as $message) {
        $token = (string) ($message['token'] ?? '');
        $plataforma = (string) ($message['plataforma'] ?? '');

        try {
            $result = $plataforma === 'android'
                ? push_fcm_send($message)
                : push_apns_send($message);
        } catch (Throwable $e) {
            $result = [
                'provider' => $plataforma === 'android' ? 'fcm' : 'apns',
                'accepted' => false,
                'error' => $e->getMessage(),
            ];
        }

        $result['plataforma'] = $plataforma;
        $result['tokenSuffix'] = substr($token, -8);
        if (isset($message['fichadaId'])) {
            $result['fichadaId'] = (int) $message['fichadaId'];
        }
        if (isset($message['usuarioId'])) {
            $result['usuarioId'] = (string) $message['usuarioId'];
        }
        $results[] = $result;
        if (($result['accepted'] ?? false) === true) {
            $sent++;
            continue;
        }

        $errors[] = strtoupper((string) ($result['provider'] ?? 'push'))
            . ': ' . (string) ($result['error'] ?? 'Envío rechazado');
    }

    return [
        'ok' => $errors === [],
        'sent' => $sent,
        'providerResults' => $results,
        'errors' => $errors,
    ];
}

/** @return array{status:int, body:string, headers:array<string, string>} */
function push_http_post(string $url, array $headers, string $body, bool $http2 = false): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('La extensión PHP cURL es requerida para FCM/APNs');
    }

    $responseHeaders = [];
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('No se pudo inicializar cURL');
    }

    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
            $length = strlen($line);
            $separator = strpos($line, ':');
            if ($separator !== false) {
                $name = strtolower(trim(substr($line, 0, $separator)));
                $responseHeaders[$name] = trim(substr($line, $separator + 1));
            }
            return $length;
        },
    ]);

    $caBundle = push_native_ca_bundle();
    if ($caBundle !== null) {
        curl_setopt($curl, CURLOPT_CAINFO, $caBundle);
    }

    if ($http2 && defined('CURL_HTTP_VERSION_2_0')) {
        curl_setopt($curl, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
    }

    $responseBody = curl_exec($curl);
    if ($responseBody === false) {
        $error = curl_error($curl);
        curl_close($curl);
        throw new RuntimeException('Error de red al enviar push: ' . $error);
    }

    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [
        'status' => $status,
        'body' => (string) $responseBody,
        'headers' => $responseHeaders,
    ];
}

function push_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/** @return array{accessToken:string, projectId:string} */
function push_fcm_credentials(): array
{
    static $cached = null;
    if (is_array($cached) && (int) ($cached['expiresAt'] ?? 0) > time() + 60) {
        return [
            'accessToken' => (string) $cached['accessToken'],
            'projectId' => (string) $cached['projectId'],
        ];
    }

    $config = push_native_config();
    $fcm = is_array($config['fcm'] ?? null) ? $config['fcm'] : [];
    $credentialsPath = trim((string) ($fcm['service_account_file'] ?? ''));
    if ($credentialsPath === '' || !is_file($credentialsPath)) {
        throw new RuntimeException('Falta configurar GOOGLE_APPLICATION_CREDENTIALS para FCM');
    }

    $credentials = json_decode((string) file_get_contents($credentialsPath), true);
    if (!is_array($credentials)) {
        throw new RuntimeException('La cuenta de servicio FCM no contiene JSON válido');
    }

    $clientEmail = trim((string) ($credentials['client_email'] ?? ''));
    $privateKey = (string) ($credentials['private_key'] ?? '');
    $projectId = trim((string) ($fcm['project_id'] ?? ''));
    if ($projectId === '') {
        $projectId = trim((string) ($credentials['project_id'] ?? ''));
    }
    $tokenUri = trim((string) ($credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token'));
    if ($clientEmail === '' || $privateKey === '' || $projectId === '') {
        throw new RuntimeException('La cuenta de servicio FCM está incompleta');
    }

    $now = time();
    $header = push_base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $claims = push_base64url_encode(json_encode([
        'iss' => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => $tokenUri,
        'iat' => $now - 5,
        'exp' => $now + 3600,
    ], JSON_THROW_ON_ERROR));
    $unsignedToken = "{$header}.{$claims}";

    $signature = '';
    if (!openssl_sign($unsignedToken, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('No se pudo firmar la autenticación FCM');
    }
    $assertion = $unsignedToken . '.' . push_base64url_encode($signature);

    $response = push_http_post(
        $tokenUri,
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ])
    );
    $decoded = json_decode($response['body'], true);
    $accessToken = is_array($decoded) ? trim((string) ($decoded['access_token'] ?? '')) : '';
    if ($response['status'] !== 200 || $accessToken === '') {
        $detail = is_array($decoded)
            ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? 'respuesta inválida')
            : 'respuesta inválida';
        throw new RuntimeException("No se pudo autenticar con FCM: {$detail}");
    }

    $expiresIn = max(300, (int) ($decoded['expires_in'] ?? 3600));
    $cached = [
        'accessToken' => $accessToken,
        'projectId' => $projectId,
        'expiresAt' => time() + $expiresIn,
    ];

    return ['accessToken' => $accessToken, 'projectId' => $projectId];
}

/** @param array<string, mixed> $message */
function push_fcm_send(array $message): array
{
    $credentials = push_fcm_credentials();
    $data = [];
    foreach (($message['data'] ?? []) as $key => $value) {
        $data[(string) $key] = is_scalar($value)
            ? (string) $value
            : json_encode($value, JSON_THROW_ON_ERROR);
    }

    $payload = json_encode([
        'message' => [
            'token' => (string) $message['token'],
            'notification' => [
                'title' => (string) $message['title'],
                'body' => (string) $message['body'],
            ],
            'data' => $data,
            'android' => [
                'priority' => 'HIGH',
                'notification' => [
                    'channel_id' => (string) ($message['channelId'] ?? 'sistema'),
                    'color' => '#007BFF',
                    'sound' => 'default',
                    'visibility' => 'PUBLIC',
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    $url = 'https://fcm.googleapis.com/v1/projects/'
        . rawurlencode($credentials['projectId'])
        . '/messages:send';
    $response = push_http_post($url, [
        'Authorization: Bearer ' . $credentials['accessToken'],
        'Content-Type: application/json; charset=UTF-8',
    ], $payload);
    $decoded = json_decode($response['body'], true);

    if ($response['status'] >= 200 && $response['status'] < 300) {
        return [
            'provider' => 'fcm',
            'accepted' => true,
            'messageId' => is_array($decoded) ? (string) ($decoded['name'] ?? '') : '',
        ];
    }

    $error = is_array($decoded) ? ($decoded['error'] ?? []) : [];
    $messageText = is_array($error)
        ? (string) ($error['message'] ?? "HTTP {$response['status']}")
        : "HTTP {$response['status']}";
    $errorCode = is_array($error) ? (string) ($error['status'] ?? '') : '';
    foreach (is_array($error) ? ($error['details'] ?? []) : [] as $detail) {
        if (is_array($detail) && isset($detail['errorCode'])) {
            $errorCode = (string) $detail['errorCode'];
        }
    }

    return [
        'provider' => 'fcm',
        'accepted' => false,
        'status' => $response['status'],
        'errorCode' => $errorCode,
        'error' => $errorCode !== '' ? "{$messageText} ({$errorCode})" : $messageText,
    ];
}

function push_der_read_length(string $der, int &$offset): int
{
    if ($offset >= strlen($der)) {
        throw new RuntimeException('Firma APNs DER inválida');
    }

    $length = ord($der[$offset++]);
    if (($length & 0x80) === 0) {
        return $length;
    }

    $bytes = $length & 0x7f;
    if ($bytes < 1 || $bytes > 4 || $offset + $bytes > strlen($der)) {
        throw new RuntimeException('Longitud DER inválida');
    }

    $length = 0;
    for ($i = 0; $i < $bytes; $i++) {
        $length = ($length << 8) | ord($der[$offset++]);
    }
    return $length;
}

function push_ecdsa_der_to_jose(string $der, int $partLength = 32): string
{
    $offset = 0;
    if (($der[$offset++] ?? '') !== "\x30") {
        throw new RuntimeException('Firma APNs DER inválida');
    }
    push_der_read_length($der, $offset);

    $parts = [];
    for ($part = 0; $part < 2; $part++) {
        if (($der[$offset++] ?? '') !== "\x02") {
            throw new RuntimeException('Firma APNs DER inválida');
        }
        $length = push_der_read_length($der, $offset);
        $value = substr($der, $offset, $length);
        $offset += $length;
        $value = ltrim($value, "\x00");
        if (strlen($value) > $partLength) {
            $value = substr($value, -$partLength);
        }
        $parts[] = str_pad($value, $partLength, "\x00", STR_PAD_LEFT);
    }

    return $parts[0] . $parts[1];
}

/** @return array{jwt:string, topic:string, environment:string} */
function push_apns_credentials(): array
{
    static $cached = null;
    if (is_array($cached) && (int) ($cached['expiresAt'] ?? 0) > time() + 60) {
        return [
            'jwt' => (string) $cached['jwt'],
            'topic' => (string) $cached['topic'],
            'environment' => (string) $cached['environment'],
        ];
    }

    $config = push_native_config();
    $apns = is_array($config['apns'] ?? null) ? $config['apns'] : [];
    $keyFile = trim((string) ($apns['key_file'] ?? ''));
    $keyId = trim((string) ($apns['key_id'] ?? ''));
    $teamId = trim((string) ($apns['team_id'] ?? ''));
    $topic = trim((string) ($apns['topic'] ?? 'ar.com.raei.sgi'));
    $environment = strtolower(trim((string) ($apns['environment'] ?? 'production')));
    if ($keyFile === '' || !is_file($keyFile) || $keyId === '' || $teamId === '') {
        throw new RuntimeException('Falta configurar la clave APNs (.p8), Key ID y Team ID');
    }

    $privateKey = (string) file_get_contents($keyFile);
    $now = time();
    $header = push_base64url_encode(json_encode([
        'alg' => 'ES256',
        'kid' => $keyId,
    ], JSON_THROW_ON_ERROR));
    $claims = push_base64url_encode(json_encode([
        'iss' => $teamId,
        'iat' => $now,
    ], JSON_THROW_ON_ERROR));
    $unsignedToken = "{$header}.{$claims}";

    $derSignature = '';
    if (!openssl_sign($unsignedToken, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('No se pudo firmar la autenticación APNs');
    }
    $jwt = $unsignedToken . '.' . push_base64url_encode(push_ecdsa_der_to_jose($derSignature));
    $cached = [
        'jwt' => $jwt,
        'topic' => $topic,
        'environment' => $environment === 'development' ? 'development' : 'production',
        'expiresAt' => $now + 3000,
    ];

    return [
        'jwt' => $cached['jwt'],
        'topic' => $cached['topic'],
        'environment' => $cached['environment'],
    ];
}

/** @param array<string, mixed> $message */
function push_apns_send(array $message): array
{
    $credentials = push_apns_credentials();
    $messageEnvironment = str_ends_with((string) $message['plataforma'], '-development')
        ? 'development'
        : 'production';
    $host = $messageEnvironment === 'development'
        ? 'https://api.development.push.apple.com'
        : 'https://api.push.apple.com';

    $payload = [
        'aps' => [
            'alert' => [
                'title' => (string) $message['title'],
                'body' => (string) $message['body'],
            ],
            'sound' => 'default',
        ],
    ];
    foreach (($message['data'] ?? []) as $key => $value) {
        $payload[(string) $key] = $value;
    }

    $response = push_http_post(
        $host . '/3/device/' . rawurlencode((string) $message['token']),
        [
            'Authorization: bearer ' . $credentials['jwt'],
            'Content-Type: application/json',
            'apns-topic: ' . $credentials['topic'],
            'apns-push-type: alert',
            'apns-priority: 10',
        ],
        json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        true
    );

    if ($response['status'] === 200) {
        return [
            'provider' => 'apns',
            'accepted' => true,
            'messageId' => (string) ($response['headers']['apns-id'] ?? ''),
        ];
    }

    $decoded = json_decode($response['body'], true);
    $reason = is_array($decoded)
        ? (string) ($decoded['reason'] ?? "HTTP {$response['status']}")
        : "HTTP {$response['status']}";
    return [
        'provider' => 'apns',
        'accepted' => false,
        'status' => $response['status'],
        'errorCode' => $reason,
        'error' => $reason,
    ];
}
