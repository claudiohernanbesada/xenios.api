<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/push_expo.php';

function password_reset_config(array $config): array
{
    $defaults = [
        'public_base_url' => 'https://restartpassword.sgi.raei.com.ar',
        'api_public_url' => 'https://api.raei.com.ar/sgi-movil',
        'ttl_seconds' => 3600,
        'mail_from' => 'noreply@raei.com.ar',
        'mail_from_name' => 'SGI - Metalúrgica RAEI',
    ];

    return array_merge($defaults, $config['password_reset'] ?? []);
}

function password_reset_new_id(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function password_reset_table_exists(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM MovilPasswordReset LIMIT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

function password_reset_hash_token(string $plainToken): string
{
    return hash('sha256', $plainToken);
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

function password_reset_invalidate_previous(PDO $pdo, string $usuarioId): void
{
    $stmt = $pdo->prepare(
        'UPDATE MovilPasswordReset SET UsedAt = NOW()
         WHERE UsuarioId = :uid AND UsedAt IS NULL'
    );
    $stmt->execute(['uid' => $usuarioId]);
}

function password_reset_create_token(PDO $pdo, string $usuarioId, int $ttlSeconds): string
{
    password_reset_invalidate_previous($pdo, $usuarioId);

    $plain = bin2hex(random_bytes(32));
    $id = password_reset_new_id();
    $expires = (new DateTimeImmutable())->modify('+' . $ttlSeconds . ' seconds');

    $stmt = $pdo->prepare(
        'INSERT INTO MovilPasswordReset (Id, UsuarioId, TokenHash, ExpiresAt)
         VALUES (:id, :uid, :hash, :exp)'
    );
    $stmt->execute([
        'id' => $id,
        'uid' => $usuarioId,
        'hash' => password_reset_hash_token($plain),
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
    $subject = 'Restablecer contraseña - SGI';
    $body =
        "Hola {$nombre},\n\n"
        . "Recibimos una solicitud para restablecer la contraseña de tu cuenta en la app SGI.\n\n"
        . "Abrí el siguiente enlace (válido por 1 hora):\n{$link}\n\n"
        . "Si no solicitaste este cambio, ignorá este correo.\n\n"
        . "Metalúrgica RAEI S.A.";

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . sprintf('"%s" <%s>', addslashes($fromName), $from),
        'Reply-To: ' . $from,
    ];

    return @mail($toMail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
}

/**
 * Solicitud desde la app: siempre responde ok genérico (no revelar si el mail existe).
 */
function password_reset_request(PDO $pdo, array $config, string $correo): array
{
    if (!password_reset_table_exists($pdo)) {
        return ['ok' => false, 'message' => 'Módulo de recuperación no instalado en el servidor'];
    }

    $pr = password_reset_config($config);
    $user = password_reset_find_user_by_mail($pdo, $correo);

    if ($user !== null) {
        $plain = password_reset_create_token($pdo, (string) $user['Id'], (int) $pr['ttl_seconds']);
        $link = password_reset_build_link($pr, $plain);
        $nombre = trim((string) $user['Nombre']);
        password_reset_send_mail($pr, (string) $user['Mail'], $nombre !== '' ? $nombre : 'Usuario', $link);
    }

    return [
        'ok' => true,
        'message' => 'Si el correo está registrado, recibirás un enlace para restablecer la contraseña.',
    ];
}

/** @return array{ok:bool,usuarioId?:string,message?:string} */
function password_reset_validate(PDO $pdo, string $plainToken): array
{
    if (!password_reset_table_exists($pdo)) {
        return ['ok' => false, 'message' => 'Módulo de recuperación no instalado'];
    }

    $hash = password_reset_hash_token($plainToken);
    $stmt = $pdo->prepare(
        'SELECT UsuarioId, ExpiresAt, UsedAt FROM MovilPasswordReset
         WHERE TokenHash = :hash ORDER BY CreatedAt DESC LIMIT 1'
    );
    $stmt->execute(['hash' => $hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || $row['UsedAt'] !== null) {
        return ['ok' => false, 'message' => 'El enlace no es válido o ya fue utilizado'];
    }

    $expires = new DateTimeImmutable((string) $row['ExpiresAt']);
    if ($expires < new DateTimeImmutable()) {
        return ['ok' => false, 'message' => 'El enlace expiró. Solicitá uno nuevo desde la app'];
    }

    return ['ok' => true, 'usuarioId' => (string) $row['UsuarioId']];
}

/** @return list<string> */
function password_reset_fetch_push_tokens(PDO $pdo, string $usuarioId): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT ExpoPushToken FROM MovilPushToken WHERE UsuarioId = :uid'
        );
        $stmt->execute(['uid' => $usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable) {
        return [];
    }
}

function password_reset_notify_app(PDO $pdo, string $usuarioId): void
{
    $tokens = password_reset_fetch_push_tokens($pdo, $usuarioId);
    if ($tokens === []) {
        return;
    }

    $messages = [];
    foreach ($tokens as $token) {
        $token = trim((string) $token);
        if ($token === '' || !str_starts_with($token, 'ExponentPushToken[')) {
            continue;
        }
        $messages[] = [
            'to' => $token,
            'title' => 'Contraseña actualizada',
            'body' => 'Tu contraseña del SGI móvil fue restablecida correctamente.',
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => 'sistema',
            'data' => [
                'screen' => 'login',
                'tipo' => 'passwordReset',
            ],
        ];
    }

    expo_push_send_batch($messages);
}

/** @return array{ok:bool,message?:string} */
function password_reset_complete(PDO $pdo, array $config, string $plainToken, string $nueva, string $confirmacion): array
{
    if ($nueva !== $confirmacion) {
        return ['ok' => false, 'message' => 'Las contraseñas no coinciden'];
    }

    $policyError = validate_password_policy($nueva);
    if ($policyError !== null) {
        return ['ok' => false, 'message' => $policyError];
    }

    $validation = password_reset_validate($pdo, $plainToken);
    if (!$validation['ok']) {
        return $validation;
    }

    $usuarioId = (string) $validation['usuarioId'];
    $hash = password_reset_hash_token($plainToken);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE Usuarios SET Passwd = :passwd, dateUpdate = NOW() WHERE Id = :id AND Estado = 1'
        );
        $stmt->execute([
            'passwd' => sha256_password($nueva),
            'id' => $usuarioId,
        ]);

        $mark = $pdo->prepare(
            'UPDATE MovilPasswordReset SET UsedAt = NOW()
             WHERE TokenHash = :hash AND UsedAt IS NULL'
        );
        $mark->execute(['hash' => $hash]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'message' => 'No se pudo actualizar la contraseña'];
    }

    password_reset_notify_app($pdo, $usuarioId);

    return ['ok' => true, 'message' => 'Contraseña actualizada correctamente'];
}
