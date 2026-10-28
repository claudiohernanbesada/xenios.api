<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tenant_mail.php';

function password_reset_config(array $config): array
{
    $defaults = [
        'public_base_url' => 'https://api.xenios.com.ar/xenios-movil/reset-password',
        'ttl_seconds' => 1800,
        'mail_from' => 'noreply@xenios.com.ar',
        'mail_from_name' => 'No Reply - Xenios',
        'mail_logo_path' => dirname(__DIR__) . '/assets/xenios-logo.png',
        'app_login_url' => 'xenios://login',
        'smtp' => [
            'host' => 'smtp.office365.com',
            'port' => 587,
            'username' => 'noreply@xenios.com.ar',
            'password' => '',
            'ssl' => true,
            'encryption' => 'tls',
            'timeout_seconds' => 20,
        ],
    ];

    $passwordResetConfig = $config['password_reset'] ?? [];
    $passwordResetConfig['smtp'] = array_merge(
        $defaults['smtp'],
        $passwordResetConfig['smtp'] ?? []
    );

    return array_merge($defaults, $passwordResetConfig);
}

/** @return array{Id:string,Nombre:string,Apellido:string,Mail:string}|null */
function password_reset_find_user_by_mail(PDO $pdo, string $mail): ?array
{
    $stmt = $pdo->prepare(
        'SELECT u.Id, u.Nombre, u.Apellido, u.Mail, u.EmpresaTenantId, u.DepositoId AS SucursalId FROM Usuarios u
         INNER JOIN Hoteles h ON h.Id = u.EmpresaTenantId AND h.Activa = 1
         WHERE LOWER(TRIM(u.Mail)) = :mail AND u.Estado = 1 LIMIT 1'
    );
    $stmt->execute(['mail' => strtolower(trim($mail))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function password_reset_create_token(PDO $pdo, string $usuarioId, int $ttlSeconds): string
{
    $plain = bin2hex(random_bytes(32));
    $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . $ttlSeconds . ' seconds');

    $stmt = $pdo->prepare(
        'UPDATE Usuarios
         SET PasswordResetToken = :token,
             PasswordResetTokenExpiration = :exp
         WHERE Id = :uid AND Estado = 1'
    );
    $stmt->execute([
        'uid' => $usuarioId,
        'token' => $plain,
        'exp' => $expires->format('Y-m-d H:i:s'),
    ]);

    return $plain;
}

function password_reset_clear_token(PDO $pdo, string $usuarioId): void
{
    $stmt = $pdo->prepare(
        'UPDATE Usuarios
         SET PasswordResetToken = NULL,
             PasswordResetTokenExpiration = NULL
         WHERE Id = :uid'
    );
    $stmt->execute(['uid' => $usuarioId]);
}

function password_reset_build_link(array $prConfig, string $plainToken): string
{
    $base = rtrim((string) $prConfig['public_base_url'], '/');

    return $base . '/?token=' . urlencode($plainToken);
}

function password_reset_send_mail(array $prConfig, string $toMail, string $nombre, string $link): bool
{
    $scope = $prConfig['hotel_mail_scope'] ?? [];
    $config = $prConfig['hotel_mail_config'] ?? [];
    $safeName = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
    $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');
    $body = '<p>Hola ' . $safeName . '.</p><p>Recibimos una solicitud para recuperar tu contraseña en Xenios.</p>'
        . '<p><a href="' . $safeLink . '">Restablecer contraseña</a></p>'
        . '<p>Si no solicitaste este cambio, ignorá el correo.</p>';
    return tenant_mail_send($config, $scope, [$toMail], 'Recuperación de contraseña · Xenios', $body);
}

function password_reset_send_smtp(
    array $prConfig,
    string $toMail,
    string $from,
    string $fromName,
    string $subject,
    string $body
): bool {
    $smtp = $prConfig['smtp'] ?? [];
    $host = trim((string) ($smtp['host'] ?? ''));
    $port = (int) ($smtp['port'] ?? 587);
    $username = trim((string) ($smtp['username'] ?? ''));
    $password = (string) ($smtp['password'] ?? '');
    $ssl = filter_var($smtp['ssl'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $encryption = strtolower(trim((string) ($smtp['encryption'] ?? ($ssl ? 'tls' : ''))));
    $timeout = max(5, (int) ($smtp['timeout_seconds'] ?? 20));

    if ($host === '' || $username === '' || $password === '') {
        error_log('[password_reset] Configuracion SMTP incompleta para recuperacion de clave');
        return false;
    }

    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        error_log('[password_reset] No se pudo conectar al SMTP: ' . $errstr . ' (' . $errno . ')');
        return false;
    }

    stream_set_timeout($socket, $timeout);

    try {
        password_reset_smtp_expect($socket, [220]);
        password_reset_smtp_command($socket, 'EHLO ' . password_reset_smtp_hostname(), [250]);

        if ($encryption === 'tls') {
            password_reset_smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No se pudo iniciar TLS con el SMTP');
            }
            password_reset_smtp_command($socket, 'EHLO ' . password_reset_smtp_hostname(), [250]);
        }

        password_reset_smtp_command($socket, 'AUTH LOGIN', [334]);
        password_reset_smtp_command($socket, base64_encode($username), [334]);
        password_reset_smtp_command($socket, base64_encode($password), [235]);
        password_reset_smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        password_reset_smtp_command($socket, 'RCPT TO:<' . $toMail . '>', [250, 251]);
        password_reset_smtp_command($socket, 'DATA', [354]);
        password_reset_smtp_write($socket, password_reset_build_mime_message($prConfig, $toMail, $from, $fromName, $subject, $body) . "\r\n.");
        password_reset_smtp_expect($socket, [250]);
        password_reset_smtp_command($socket, 'QUIT', [221]);

        fclose($socket);
        return true;
    } catch (Throwable $e) {
        error_log('[password_reset] Error SMTP recuperacion de clave: ' . $e->getMessage());
        @fclose($socket);
        return false;
    }
}

function password_reset_build_mime_message(
    array $prConfig,
    string $toMail,
    string $from,
    string $fromName,
    string $subject,
    string $body
): string {
    $logoPath = trim((string) ($prConfig['mail_logo_path'] ?? ''));
    $logoBytes = $logoPath !== '' && is_file($logoPath) && is_readable($logoPath)
        ? file_get_contents($logoPath)
        : false;
    $logoCid = 'logo-xenios-reset';
    $relatedBoundary = 'xenios-reset-' . bin2hex(random_bytes(8));
    $alternativeBoundary = 'xenios-reset-alt-' . bin2hex(random_bytes(8));
    $html = password_reset_build_html_body($body, $logoBytes !== false ? $logoCid : null);

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/related; boundary="' . $relatedBoundary . '"',
        'From: ' . password_reset_format_mailbox($from, $fromName),
        'Reply-To: ' . $from,
        'To: ' . $toMail,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n";
    $message .= '--' . $relatedBoundary . "\r\n";
    $message .= 'Content-Type: multipart/alternative; boundary="' . $alternativeBoundary . '"' . "\r\n\r\n";
    $message .= '--' . $alternativeBoundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= password_reset_dot_stuff($body) . "\r\n\r\n";
    $message .= '--' . $alternativeBoundary . "\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= password_reset_dot_stuff($html) . "\r\n\r\n";
    $message .= '--' . $alternativeBoundary . "--\r\n";

    if ($logoBytes !== false) {
        $message .= '--' . $relatedBoundary . "\r\n";
        $message .= "Content-Type: image/png; name=\"logoXenios.png\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-ID: <{$logoCid}>\r\n";
        $message .= "Content-Disposition: inline; filename=\"logoXenios.png\"\r\n\r\n";
        $message .= chunk_split(base64_encode($logoBytes), 76, "\r\n") . "\r\n";
    }

    $message .= '--' . $relatedBoundary . '--';

    return $message;
}

function password_reset_build_html_body(string $textBody, ?string $logoCid): string
{
    $lines = preg_split('/\R/', $textBody) ?: [];
    $paragraphs = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (filter_var($line, FILTER_VALIDATE_URL)) {
            $safeUrl = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
            $paragraphs[] = '<p style="margin:0 0 16px;"><a href="' . $safeUrl . '" style="color:#1a73e8;">' . $safeUrl . '</a></p>';
            continue;
        }

        $paragraphs[] = '<p style="margin:0 0 16px;">' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $logo = $logoCid !== null
        ? '<div style="text-align:left;margin:0 0 24px;"><img src="cid:' . htmlspecialchars($logoCid, ENT_QUOTES, 'UTF-8') . '" alt="XENIOS" style="display:inline-block;max-width:140px;height:auto;border:0;" /></div>'
        : '';

    return '<!DOCTYPE html><html lang="es"><body style="margin:0;padding:24px;background:#ffffff;color:#202124;font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5;">'
        . '<div style="max-width:640px;margin:0 auto;">'
        . $logo
        . implode('', $paragraphs)
        . '</div></body></html>';
}

function password_reset_format_mailbox(string $mail, string $name): string
{
    $encodedName = '=?UTF-8?B?' . base64_encode($name) . '?=';

    return $encodedName . ' <' . $mail . '>';
}

function password_reset_dot_stuff(string $body): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $body);
    $normalized = preg_replace('/^\./m', '..', $normalized) ?? $normalized;

    return str_replace("\n", "\r\n", $normalized);
}

function password_reset_smtp_hostname(): string
{
    $host = gethostname();

    return is_string($host) && trim($host) !== '' ? trim($host) : 'localhost';
}

/** @param resource $socket */
function password_reset_smtp_command($socket, string $command, array $expectedCodes): string
{
    password_reset_smtp_write($socket, $command);

    return password_reset_smtp_expect($socket, $expectedCodes);
}

/** @param resource $socket */
function password_reset_smtp_write($socket, string $line): void
{
    if (fwrite($socket, $line . "\r\n") === false) {
        throw new RuntimeException('No se pudo escribir al servidor SMTP');
    }
}

/** @param resource $socket */
function password_reset_smtp_expect($socket, array $expectedCodes): string
{
    $response = '';

    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (preg_match('/^(\d{3})([\s-])/', $line, $matches) === 1 && $matches[2] === ' ') {
            $code = (int) $matches[1];
            if (!in_array($code, $expectedCodes, true)) {
                throw new RuntimeException('Respuesta SMTP inesperada: ' . trim($response));
            }

            return $response;
        }
    }

    $meta = stream_get_meta_data($socket);
    if (($meta['timed_out'] ?? false) === true) {
        throw new RuntimeException('Timeout esperando respuesta SMTP');
    }

    throw new RuntimeException('El servidor SMTP cerro la conexion');
}

/**
 * Solicitud desde la app: siempre responde ok generico para no revelar si el mail existe.
 */
function password_reset_request(PDO $pdo, array $config, string $correo): array
{
    $pr = password_reset_config($config);
    $user = password_reset_find_user_by_mail($pdo, $correo);

    if ($user !== null) {
        $plain = password_reset_create_token($pdo, (string) $user['Id'], (int) $pr['ttl_seconds']);
        $link = password_reset_build_link($pr, $plain);
        $nombre = trim((string) $user['Nombre']);
        $pr['hotel_mail_scope'] = $user;
        $pr['hotel_mail_config'] = $config;
        $sent = password_reset_send_mail($pr, (string) $user['Mail'], $nombre !== '' ? $nombre : 'Usuario', $link);
        if (!$sent) {
            password_reset_clear_token($pdo, (string) $user['Id']);
            error_log('[password_reset] No se pudo enviar el correo de recuperación.');
        }
    }

    return [
        'ok' => true,
        'message' => 'Solicitud enviada. Si el correo esta registrado, recibiras un enlace para restablecer la contrasena.',
    ];
}

/** @return array{ok:bool,usuarioId?:string,message?:string} */
function password_reset_validate(PDO $pdo, string $plainToken): array
{
    $stmt = $pdo->prepare(
        'SELECT Id, PasswordResetTokenExpiration
         FROM Usuarios
         WHERE PasswordResetToken = :token AND Estado = 1
         LIMIT 1'
    );
    $stmt->execute(['token' => $plainToken]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'message' => 'El enlace no es valido o ya fue utilizado'];
    }

    $expiresRaw = (string) ($row['PasswordResetTokenExpiration'] ?? '');
    if ($expiresRaw === '') {
        return ['ok' => false, 'message' => 'El enlace no es valido o ya fue utilizado'];
    }

    $expires = new DateTimeImmutable($expiresRaw, new DateTimeZone('UTC'));
    if ($expires < new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
        return [
            'ok' => false,
            'message' => 'El tiempo para resetear la contraseña superó el tiempo permitido. Deberá volver a gestionar la recuperación de la contraseña.',
        ];
    }

    return ['ok' => true, 'usuarioId' => (string) $row['Id']];
}

/** @return array{ok:bool,message?:string} */
function password_reset_complete(PDO $pdo, array $config, string $plainToken, string $nueva, string $confirmacion): array
{
    if ($nueva !== $confirmacion) {
        return ['ok' => false, 'message' => 'Las contrasenas no coinciden'];
    }

    $policyError = validate_password_policy($nueva);
    if ($policyError !== null) {
        return ['ok' => false, 'message' => $policyError];
    }

    $validation = password_reset_validate($pdo, $plainToken);
    if (!$validation['ok']) {
        return $validation;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE Usuarios
             SET Passwd = :passwd,
                 PasswordResetToken = NULL,
                 PasswordResetTokenExpiration = NULL,
                 dateUpdate = NOW(),
                 LastChPasswd = NOW()
             WHERE Id = :id
               AND Estado = 1
               AND PasswordResetToken = :token'
        );
        $stmt->execute([
            'passwd' => sha256_password($nueva),
            'id' => (string) $validation['usuarioId'],
            'token' => $plainToken,
        ]);

        if ($stmt->rowCount() < 1) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => 'El enlace no es valido o ya fue utilizado'];
        }

        $pdo->commit();
    } catch (Throwable) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'No se pudo actualizar la contrasena'];
    }

    return ['ok' => true, 'message' => 'El cambio de contraseña se realizó con éxito'];
}
