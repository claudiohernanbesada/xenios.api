<?php
declare(strict_types=1);

require_once __DIR__ . '/push_expo.php';

function novedades_new_id(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function novedades_tables_exist(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM MovilNovedad LIMIT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

/** Usuarios activos con rol EMPLEADO (o todos los del array si se pasa). */
function novedades_resolve_destinatarios(PDO $pdo, ?array $usuarioIds): array
{
    if ($usuarioIds !== null && $usuarioIds !== []) {
        $ids = array_values(array_unique(array_map('strval', $usuarioIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT u.Id FROM Usuarios u
             WHERE u.Estado = 1 AND u.Id IN ($placeholders)"
        );
        $stmt->execute($ids);

        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    $stmt = $pdo->query(
        'SELECT DISTINCT u.Id
         FROM Usuarios u
         INNER JOIN RolUsuario ru ON ru.UsuariosId = u.Id
         INNER JOIN Roles r ON r.Id = ru.RolesId
         WHERE u.Estado = 1 AND r.RolName = \'EMPLEADO\''
    );

    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * @param list<string> $usuarioIds
 * @return array{novedadId: string, destinatarios: int, pushEnviados: int, pushErrores: list<string>}
 */
function novedades_crear_y_enviar(
    PDO $pdo,
    string $titulo,
    string $cuerpo,
    array $usuarioIds
): array {
    $novedadId = novedades_new_id();
    $ahora = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO MovilNovedad (Id, Titulo, Cuerpo, CreadoEn) VALUES (:id, :titulo, :cuerpo, :creado)'
        );
        $ins->execute([
            'id' => $novedadId,
            'titulo' => $titulo,
            'cuerpo' => $cuerpo,
            'creado' => $ahora,
        ]);

        $link = $pdo->prepare(
            'INSERT INTO MovilNovedadUsuario (NovedadId, UsuarioId, LeidaEn) VALUES (:nid, :uid, NULL)'
        );
        foreach ($usuarioIds as $uid) {
            $link->execute(['nid' => $novedadId, 'uid' => $uid]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $tokens = novedades_tokens_por_usuarios($pdo, $usuarioIds);
    $messages = expo_push_build_novedad_messages($tokens, $novedadId, $titulo, $cuerpo);
    $push = expo_push_send_batch($messages);

    return [
        'novedadId' => $novedadId,
        'destinatarios' => count($usuarioIds),
        'pushEnviados' => (int) ($push['sent'] ?? 0),
        'pushErrores' => $push['errors'] ?? [],
    ];
}

/** @param list<string> $usuarioIds */
function novedades_tokens_por_usuarios(PDO $pdo, array $usuarioIds): array
{
    if ($usuarioIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($usuarioIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT ExpoPushToken FROM MovilPushToken WHERE UsuarioId IN ($placeholders)"
    );
    $stmt->execute(array_values($usuarioIds));

    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function novedades_registrar_token(
    PDO $pdo,
    string $usuarioId,
    string $expoToken,
    ?string $plataforma
): void {
    $expoToken = trim($expoToken);
    if ($expoToken === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT Id FROM MovilPushToken WHERE ExpoPushToken = :token LIMIT 1'
    );
    $stmt->execute(['token' => $expoToken]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    $ahora = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    if ($existing) {
        $upd = $pdo->prepare(
            'UPDATE MovilPushToken SET UsuarioId = :uid, Plataforma = :plat, ActualizadoEn = :act WHERE Id = :id'
        );
        $upd->execute([
            'uid' => $usuarioId,
            'plat' => $plataforma,
            'act' => $ahora,
            'id' => $existing['Id'],
        ]);
        return;
    }

    $ins = $pdo->prepare(
        'INSERT INTO MovilPushToken (Id, UsuarioId, ExpoPushToken, Plataforma, ActualizadoEn)
         VALUES (:id, :uid, :token, :plat, :act)'
    );
    $ins->execute([
        'id' => novedades_new_id(),
        'uid' => $usuarioId,
        'token' => $expoToken,
        'plat' => $plataforma,
        'act' => $ahora,
    ]);
}

/** @return list<array<string, mixed>> */
function novedades_listar_usuario(PDO $pdo, string $usuarioId, int $limit = 100): array
{
    $stmt = $pdo->prepare(
        'SELECT n.Id, n.Titulo, n.Cuerpo, n.CreadoEn, nu.LeidaEn
         FROM MovilNovedadUsuario nu
         INNER JOIN MovilNovedad n ON n.Id = nu.NovedadId
         WHERE nu.UsuarioId = :uid
         ORDER BY n.CreadoEn DESC
         LIMIT ' . max(1, min($limit, 200))
    );
    $stmt->execute(['uid' => $usuarioId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        $leidaEn = $row['LeidaEn'] ?? null;

        return [
            'id' => (string) $row['Id'],
            'titulo' => (string) $row['Titulo'],
            'cuerpo' => (string) $row['Cuerpo'],
            'creadoEn' => novedades_format_datetime($row['CreadoEn'] ?? ''),
            'leida' => $leidaEn !== null && $leidaEn !== '',
            'leidaEn' => $leidaEn ? novedades_format_datetime((string) $leidaEn) : null,
        ];
    }, $rows);
}

function novedades_contar_no_leidas(PDO $pdo, string $usuarioId): int
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM MovilNovedadUsuario
         WHERE UsuarioId = :uid AND LeidaEn IS NULL'
    );
    $stmt->execute(['uid' => $usuarioId]);

    return (int) $stmt->fetchColumn();
}

function novedades_marcar_leida(PDO $pdo, string $usuarioId, string $novedadId): bool
{
    $stmt = $pdo->prepare(
        'UPDATE MovilNovedadUsuario SET LeidaEn = :leida
         WHERE UsuarioId = :uid AND NovedadId = :nid AND LeidaEn IS NULL'
    );
    $ahora = (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    $stmt->execute(['leida' => $ahora, 'uid' => $usuarioId, 'nid' => $novedadId]);

    return $stmt->rowCount() > 0;
}

function novedades_format_datetime(string $value): string
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if ($dt) {
        return $dt->format('c');
    }

    return $value;
}

function novedades_validar_api_key(array $config): bool
{
    $expected = (string) ($config['novedades_api_key'] ?? '');
    if ($expected === '') {
        return false;
    }

    $provided = (string) (
        $_SERVER['HTTP_X_NOVEDADES_API_KEY']
        ?? $_SERVER['HTTP_X_API_KEY']
        ?? ''
    );

    return $provided !== '' && hash_equals($expected, $provided);
}
