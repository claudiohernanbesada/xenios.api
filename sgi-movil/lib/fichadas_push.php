<?php
declare(strict_types=1);

require_once __DIR__ . '/push_native.php';

function fichadas_push_ensure_log_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS MovilFichadaPushLog (
            FichadaId INT NOT NULL,
            UsuarioId CHAR(36) NOT NULL,
            EnviadoEn DATETIME NOT NULL,
            PRIMARY KEY (FichadaId, UsuarioId),
            INDEX IX_MovilFichadaPushLog_UsuarioId (UsuarioId),
            INDEX IX_MovilFichadaPushLog_EnviadoEn (EnviadoEn)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function fichadas_push_has_token_table(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM MovilPushToken LIMIT 1');
        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * @return array{ok:bool, fichadasDetectadas:int, pushEnviados:int, usuariosNotificados:int, pushErrores:list<string>}
 */
function fichadas_push_procesar(
    PDO $pdo,
    int $lookbackHours = 24,
    int $limit = 200,
    bool $baselineOnly = false
): array
{
    if (!fichadas_push_has_token_table($pdo)) {
        return [
            'ok' => false,
            'fichadasDetectadas' => 0,
            'pushEnviados' => 0,
            'usuariosNotificados' => 0,
            'pushErrores' => ['No existe la tabla MovilPushToken'],
        ];
    }

    fichadas_push_ensure_log_table($pdo);

    $lookbackHours = max(1, min($lookbackHours, 168));
    $limit = max(1, min($limit, 500));

    $sql = "
        SELECT
            fg.Id AS FichadaId,
            fg.Fecha,
            u.Id AS UsuarioId,
            mpt.ExpoPushToken,
            mpt.Plataforma
        FROM FichadasGlobal fg
        INNER JOIN Personal p
            ON CAST(fg.Legajo AS UNSIGNED) = p.Legajo
           AND p.Estado IN (1, 2)
        INNER JOIN Usuarios u
            ON u.Estado = 1
           AND (
                LOWER(TRIM(u.Mail)) = LOWER(TRIM(p.EmailCorporativo))
                OR LOWER(TRIM(u.Mail)) = LOWER(TRIM(p.Email))
           )
        INNER JOIN MovilPushToken mpt
            ON mpt.UsuarioId = u.Id
        LEFT JOIN MovilFichadaPushLog l
            ON l.FichadaId = fg.Id
           AND l.UsuarioId = u.Id
        WHERE fg.Estado = 1
          AND fg.Fecha >= DATE_SUB(NOW(), INTERVAL {$lookbackHours} HOUR)
          AND l.FichadaId IS NULL
        ORDER BY fg.Fecha ASC
        LIMIT {$limit}";

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return [
            'ok' => true,
            'fichadasDetectadas' => 0,
            'pushEnviados' => 0,
            'usuariosNotificados' => 0,
            'pushErrores' => [],
        ];
    }

    $messageItems = [];
    $logKeys = [];
    foreach ($rows as $row) {
        $dt = new DateTimeImmutable((string) $row['Fecha']);
        $messageItems[] = [
            'token' => (string) $row['ExpoPushToken'],
            'plataforma' => (string) $row['Plataforma'],
            'fecha' => $dt->format('d/m/Y'),
            'hora' => $dt->format('H:i'),
        ];

        $key = (int) $row['FichadaId'] . '|' . (string) $row['UsuarioId'];
        $logKeys[$key] = [
            'fichadaId' => (int) $row['FichadaId'],
            'usuarioId' => (string) $row['UsuarioId'],
        ];
    }

    if ($baselineOnly) {
        fichadas_push_insert_logs($pdo, $logKeys);
        return [
            'ok' => true,
            'fichadasDetectadas' => count($logKeys),
            'pushEnviados' => 0,
            'usuariosNotificados' => 0,
            'pushErrores' => [],
            'baselineOnly' => true,
        ];
    }

    $messages = push_native_build_fichada_messages($messageItems);
    $push = push_native_send_batch($messages);

    if ($messages !== [] && (int) ($push['sent'] ?? 0) === count($messages)) {
        fichadas_push_insert_logs($pdo, $logKeys);
    }

    return [
        'ok' => (bool) ($push['ok'] ?? true),
        'fichadasDetectadas' => count($logKeys),
        'pushEnviados' => (int) ($push['sent'] ?? 0),
        'usuariosNotificados' => count($logKeys),
        'pushErrores' => $push['errors'] ?? [],
    ];
}

/** @param array<string, array{fichadaId:int, usuarioId:string}> $logKeys */
function fichadas_push_insert_logs(PDO $pdo, array $logKeys): void
{
    if ($logKeys === []) {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO MovilFichadaPushLog (FichadaId, UsuarioId, EnviadoEn)
         VALUES (:fichadaId, :usuarioId, NOW())'
    );
    foreach ($logKeys as $log) {
        $stmt->execute($log);
    }
}
