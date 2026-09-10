<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function password_reset_config(array $config): array
{
    $defaults = [
        'public_base_url' => 'https://restartpassword.sgi.raei.com.ar',
        'ttl_seconds' => 1800,
        'mail_from' => 'noreply@raei.com.ar',
        'mail_from_name' => 'No Reply - Metalurgica RAEI',
        'app_login_url' => 'sgi://login',
        'smtp' => [
            'host' => 'smtp.office365.com',
            'port' => 587,
            'username' => 'noreply@raei.com.ar',
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
        'SELECT Id, Nombre, Apellido, Mail FROM Usuarios
         WHERE LOWER(TRIM(Mail)) = :mail AND Estado = 1 LIMIT 1'
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
    $from = (string) $prConfig['mail_from'];
    $fromName = (string) $prConfig['mail_from_name'];
    $subject = 'Restablecer contrasena - SGI';
    $body =
        "Hola {$nombre},\n\n"
        . "Recibimos una solicitud para restablecer la contrasena de tu cuenta en la app SGI.\n\n"
        . "Abri el siguiente enlace (valido por 30 minutos):\n{$link}\n\n"
        . "Si no solicitaste este cambio, ignora este correo.\n\n"
        . "Metalurgica RAEI S.A.";

    return password_reset_send_smtp($prConfig, $toMail, $from, $fromName, $subject, $body);
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
        password_reset_smtp_write($socket, password_reset_build_mime_message($toMail, $from, $fromName, $subject, $body) . "\r\n.");
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
    string $toMail,
    string $from,
    string $fromName,
    string $subject,
    string $body
): string {
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . password_reset_format_mailbox($from, $fromName),
        'Reply-To: ' . $from,
        'To: ' . $toMail,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
    ];

    return implode("\r\n", $headers) . "\r\n\r\n" . password_reset_dot_stuff($body);
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
        $sent = password_reset_send_mail($pr, (string) $user['Mail'], $nombre !== '' ? $nombre : 'Usuario', $link);
        if (!$sent) {
            password_reset_clear_token($pdo, (string) $user['Id']);
            error_log('[password_reset] No se pudo enviar el correo de recuperacion a ' . (string) $user['Mail']);
            return [
                'ok' => false,
                'message' => 'En estos momentos no es posible enviar la solicitud de recuperación. Intente en unos minutos o comuníquese con la Gerencia de IT.',
            ];
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
