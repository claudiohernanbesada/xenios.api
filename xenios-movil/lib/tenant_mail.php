<?php
declare(strict_types=1);

/** Payload sin credenciales SMTP: el servicio web resuelve la cuenta del hotel/sucursal. */
function tenant_mail_payload(array $scope, array $recipients, string $subject, string $html): array
{
    $hotel = strtolower(trim((string) ($scope['EmpresaTenantId'] ?? '')));
    $branch = trim((string) ($scope['SucursalId'] ?? ''));
    $user = trim((string) ($scope['Id'] ?? ''));
    $guid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';
    if (!preg_match($guid, $hotel) || $hotel === '00000000-0000-0000-0000-000000000000'
        || ($branch !== '' && !preg_match($guid, $branch)) || ($user !== '' && !preg_match($guid, $user))) {
        throw new InvalidArgumentException('No se pudo determinar el hotel de correo.');
    }
    $recipients = array_values(array_unique(array_map('trim', $recipients)));
    if ($recipients === [] || count($recipients) > 50 || array_filter($recipients, static fn($mail) => !filter_var($mail, FILTER_VALIDATE_EMAIL))) {
        throw new InvalidArgumentException('Destinatarios de correo inválidos.');
    }
    return ['empresaId' => $hotel, 'sucursalId' => $branch !== '' ? $branch : null,
        'usuarioId' => $user !== '' ? $user : null, 'destinos' => $recipients, 'asunto' => $subject, 'html' => $html];
}

function tenant_mail_send(array $config, array $scope, array $recipients, string $subject, string $html): bool
{
    try {
        $payload = tenant_mail_payload($scope, $recipients, $subject, $html);
        $base = rtrim((string) ($config['xenios_web_base_url'] ?? ''), '/');
        $key = (string) ($config['hotel_mail']['service_key'] ?? '');
        if (parse_url($base, PHP_URL_SCHEME) !== 'https' || $key === '' || !function_exists('curl_init')) {
            throw new RuntimeException('Falta configurar el servicio de correo por hotel.');
        }
        $curl = curl_init($base . '/internal/correo/enviar');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Service-Key: ' . $key],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return $status === 200 && is_string($body) && (json_decode($body, true)['enviado'] ?? false) === true;
    } catch (Throwable $error) {
        error_log('[tenant_mail] Falló el servicio de correo: ' . get_class($error));
        return false;
    }
}
