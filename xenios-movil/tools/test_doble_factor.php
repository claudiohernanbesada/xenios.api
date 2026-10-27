<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/auth.php';

function comprobar(bool $condicion, string $nombre): void
{
    if (!$condicion) throw new RuntimeException('Fallo: ' . $nombre);
}

$key = bin2hex(random_bytes(32));
$config = ['doble_factor' => ['base_url' => 'http://127.0.0.1:5090', 'bridge_token' => $key]];
$id = '00000000-0000-0000-0000-000000000010';
$version = '00000000-0000-0000-0000-000000000020';
$user = ['Id' => $id, 'RequiereDobleFactor' => 1, 'DobleFactorVersion' => $version, 'DobleFactorConfigurado' => 1];
$viejo = issue_token($id, 'prueba@example.invalid', $key, 60);
$nuevo = issue_token($id, 'prueba@example.invalid', $key, 60, $version);
comprobar(!doble_factor_token_valido($user, verify_token($viejo, $key)), 'token anterior no evita 2FA');
comprobar(doble_factor_token_valido($user, verify_token($nuevo, $key)), 'token con 2FA');
comprobar(!doble_factor_token_valido(array_replace($user, ['DobleFactorVersion' => 'otra']), verify_token($nuevo, $key)), 'restablecimiento revoca');
comprobar(!doble_factor_token_valido(array_replace($user, ['DobleFactorConfigurado' => 0]), verify_token($nuevo, $key)), 'configuracion pendiente no autentica');
comprobar(doble_factor_token_valido(['RequiereDobleFactor' => 0], verify_token($viejo, $key)), 'usuario sin requisito');
comprobar(verify_token($nuevo . 'a', $key) === null, 'firma adulterada');
comprobar(verify_token($nuevo, '') === null, 'sin clave no autentica');
comprobar(verify_token(issue_token($id, 'prueba@example.invalid', $key, -60), $key) === null, 'token vencido');
$ts = (string) time();
$proof = ['usuarioId' => $id, 'version' => $version, 'timestamp' => $ts,
    'firma' => doble_factor_firma($key, 'movil', $id, $ts, $version)];
comprobar(doble_factor_prueba_valida($config, $proof), 'prueba del verificador');
comprobar(!doble_factor_prueba_valida($config, array_replace($proof, ['firma' => doble_factor_firma($key, 'web', $id, $ts, $version)])), 'canales separados');
comprobar(!doble_factor_prueba_valida($config, array_replace($proof, ['timestamp' => '1'])), 'prueba vencida');
$_SERVER['HTTP_X_XENIOS_WEB_MFA_VERSION'] = $version;
$_SERVER['HTTP_X_XENIOS_WEB_MFA_SIGNATURE'] = 'falso';
comprobar(!doble_factor_puente_web_valido($config, $user, $ts), 'puente web sin prueba no evita 2FA');
$_SERVER['HTTP_X_XENIOS_WEB_MFA_SIGNATURE'] = doble_factor_firma($key, 'web', $id, $ts, $version);
comprobar(doble_factor_puente_web_valido($config, $user, $ts), 'puente web autenticado');
try {
    doble_factor_config(['doble_factor' => ['base_url' => 'http://example.invalid', 'bridge_token' => $key]]);
    throw new LogicException('Se permitio destino no local');
} catch (RuntimeException $e) {
    comprobar(!($e instanceof LogicException), 'solo loopback');
}
echo "OK: 14 verificaciones de tokens, revocacion, firmas, canales y configuracion local.\n";
