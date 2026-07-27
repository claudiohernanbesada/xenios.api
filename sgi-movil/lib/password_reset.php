<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function password_reset_config(array $config): array
{
    $defaults = [
        'public_base_url' => 'https://restartpassword.sgi.raei.com.ar',
        'ttl_seconds' => 3600,
        'mail_from' => 'noreply@raei.com.ar',
        'mail_from_name' => 'SGI - Metalurgica RAEI',
    ];

    return array_merge($defaults, $config['password_reset'] ?? []);
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
        . "Abri el siguiente enlace (valido por 1 hora):\n{$link}\n\n"
        . "Si no solicitaste este cambio, ignora este correo.\n\n"
        . "Metalurgica RAEI S.A.";

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . sprintf('"%s" <%s>', addslashes($fromName), $from),
        'Reply-To: ' . $from,
    ];

    return @mail($toMail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
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
            error_log('[password_reset] No se pudo enviar el correo de recuperacion a ' . (string) $user['Mail']);
            return [
                'ok' => false,
                'message' => 'No se pudo enviar el correo de recuperacion. Intenta nuevamente en unos minutos o avisa a Sistemas.',
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
        return ['ok' => false, 'message' => 'El enlace expiro. Solicita uno nuevo desde la app'];
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
                 dateUpdate = NOW()
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

    return ['ok' => true, 'message' => 'Contrasena actualizada correctamente'];
}
