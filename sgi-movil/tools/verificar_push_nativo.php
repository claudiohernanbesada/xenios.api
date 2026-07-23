<?php
/** Uso: php tools/verificar_push_nativo.php [android|ios|all] */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Solo CLI');
}

require_once dirname(__DIR__) . '/lib/push_native.php';

$target = strtolower(trim((string) ($argv[1] ?? 'all')));
if (!in_array($target, ['android', 'ios', 'all'], true)) {
    fwrite(STDERR, "Uso: php tools/verificar_push_nativo.php [android|ios|all]\n");
    exit(1);
}

$config = require dirname(__DIR__) . '/config.php';
$push = is_array($config['push'] ?? null) ? $config['push'] : [];
$fcm = is_array($push['fcm'] ?? null) ? $push['fcm'] : [];
$apns = is_array($push['apns'] ?? null) ? $push['apns'] : [];
$fcmFile = trim((string) ($fcm['service_account_file'] ?? ''));
$apnsFile = trim((string) ($apns['key_file'] ?? ''));

$cryptoOk = false;
if (extension_loaded('openssl') && $apnsFile !== '' && is_file($apnsFile)) {
    $key = openssl_pkey_get_private((string) file_get_contents($apnsFile));
    if ($key !== false) {
        $der = '';
        $cryptoOk = openssl_sign('sgi-push-test', $der, $key, OPENSSL_ALGO_SHA256)
            && strlen(push_ecdsa_der_to_jose($der)) === 64;
    }
}

$curlInfo = function_exists('curl_version') ? curl_version() : [];
$curlFeatures = (int) ($curlInfo['features'] ?? 0);
$fcmJson = $fcmFile !== '' && is_file($fcmFile)
    ? json_decode((string) file_get_contents($fcmFile), true)
    : null;
$fcmCredentialsOk = is_array($fcmJson)
    && trim((string) ($fcmJson['client_email'] ?? '')) !== ''
    && trim((string) ($fcmJson['private_key'] ?? '')) !== '';
$fcmSignatureOk = false;
if ($fcmCredentialsOk && extension_loaded('openssl')) {
    $signature = '';
    $fcmSignatureOk = openssl_sign(
        'sgi-fcm-test',
        $signature,
        (string) $fcmJson['private_key'],
        OPENSSL_ALGO_SHA256
    );
}
$fcmProjectId = trim((string) ($fcm['project_id'] ?? ''));
if ($fcmProjectId === '' && is_array($fcmJson)) {
    $fcmProjectId = trim((string) ($fcmJson['project_id'] ?? ''));
}
$result = [
    'ok' => true,
    'target' => $target,
    'runtime' => [
        'curl' => function_exists('curl_init'),
        'http2' => defined('CURL_VERSION_HTTP2')
            && ($curlFeatures & CURL_VERSION_HTTP2) === CURL_VERSION_HTTP2,
        'openssl' => extension_loaded('openssl'),
        'firmaRs256' => $fcmSignatureOk,
        'firmaEs256' => $cryptoOk,
        'tokensNativos' =>
            push_native_token_valido(str_repeat('a', 32), 'android')
            && push_native_token_valido(str_repeat('b', 64), 'ios-production')
            && !push_native_token_valido('ExponentPushToken[legacy]', 'android'),
    ],
    'fcm' => [
        'credentialsConfigured' => $fcmCredentialsOk,
        'projectIdConfigured' => $fcmProjectId !== '',
    ],
    'apns' => [
        'keyConfigured' => $apnsFile !== '' && is_file($apnsFile),
        'keyIdConfigured' => trim((string) ($apns['key_id'] ?? '')) !== '',
        'teamIdConfigured' => trim((string) ($apns['team_id'] ?? '')) !== '',
        'topic' => (string) ($apns['topic'] ?? ''),
    ],
];

$androidOk =
    $result['runtime']['curl']
    && $result['runtime']['openssl']
    && $result['runtime']['firmaRs256']
    && $result['runtime']['tokensNativos']
    && $result['fcm']['credentialsConfigured']
    && $result['fcm']['projectIdConfigured'];
$iosOk =
    $result['runtime']['curl']
    && $result['runtime']['http2']
    && $result['runtime']['openssl']
    && $result['runtime']['firmaEs256']
    && $result['runtime']['tokensNativos']
    && $result['apns']['keyConfigured']
    && $result['apns']['keyIdConfigured']
    && $result['apns']['teamIdConfigured'];
$result['androidOk'] = $androidOk;
$result['iosOk'] = $iosOk;
$result['ok'] = match ($target) {
    'android' => $androidOk,
    'ios' => $iosOk,
    default => $androidOk && $iosOk,
};

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 2);
