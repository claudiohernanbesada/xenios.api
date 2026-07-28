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
            Provider VARCHAR(20) NULL,
            ProviderMessageId VARCHAR(255) NULL,
            Plataforma VARCHAR(30) NULL,
            TokenSuffix VARCHAR(16) NULL,
            EstadoEnvio VARCHAR(20) NULL,
            ErrorEnvio TEXT NULL,
            RecibidoEn DATETIME NULL,
            VisualizadoEn DATETIME NULL,
            PRIMARY KEY (FichadaId, UsuarioId),
            INDEX IX_MovilFichadaPushLog_UsuarioId (UsuarioId),
            INDEX IX_MovilFichadaPushLog_EnviadoEn (EnviadoEn)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    fichadas_push_ensure_log_column($pdo, 'Provider', 'VARCHAR(20) NULL');
    fichadas_push_ensure_log_column($pdo, 'ProviderMessageId', 'VARCHAR(255) NULL');
    fichadas_push_ensure_log_column($pdo, 'Plataforma', 'VARCHAR(30) NULL');
    fichadas_push_ensure_log_column($pdo, 'TokenSuffix', 'VARCHAR(16) NULL');
    fichadas_push_ensure_log_column($pdo, 'EstadoEnvio', 'VARCHAR(20) NULL');
    fichadas_push_ensure_log_column($pdo, 'ErrorEnvio', 'TEXT NULL');
    fichadas_push_ensure_log_column($pdo, 'RecibidoEn', 'DATETIME NULL');
    fichadas_push_ensure_log_column($pdo, 'VisualizadoEn', 'DATETIME NULL');
}

function fichadas_push_ensure_log_column(PDO $pdo, string $column, string $definition): void
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'MovilFichadaPushLog'
           AND COLUMN_NAME = :column"
    );
    $stmt->execute(['column' => $column]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $pdo->exec("ALTER TABLE MovilFichadaPushLog ADD COLUMN {$column} {$definition}");
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
    $now = new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
    $desde = $now->sub(new DateInterval("PT{$lookbackHours}H"))->format('Y-m-d H:i:s');

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
           AND COALESCE(l.EstadoEnvio, 'enviado') = 'enviado'
        WHERE fg.Estado = 1
          AND fg.Fecha >= :desde
          AND l.FichadaId IS NULL
        ORDER BY fg.Fecha ASC
        LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['desde' => $desde]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
            'fichadaId' => (int) $row['FichadaId'],
            'usuarioId' => (string) $row['UsuarioId'],
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
    fichadas_push_guardar_resultados_envio($pdo, $logKeys, $push['providerResults'] ?? []);

    return [
        'ok' => (bool) ($push['ok'] ?? true),
        'fichadasDetectadas' => count($logKeys),
        'pushEnviados' => (int) ($push['sent'] ?? 0),
        'usuariosNotificados' => count($logKeys),
        'pushResultados' => $push['providerResults'] ?? [],
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
         VALUES (:fichadaId, :usuarioId, :enviadoEn)'
    );
    $enviadoEn = (new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
        ->format('Y-m-d H:i:s');
    foreach ($logKeys as $log) {
        $log['enviadoEn'] = $enviadoEn;
        $stmt->execute($log);
    }
}

/** @param list<array<string, mixed>> $providerResults */
function fichadas_push_guardar_resultados_envio(PDO $pdo, array $logKeys, array $providerResults): void
{
    if ($logKeys === []) {
        return;
    }

    $resultsByKey = [];
    foreach ($providerResults as $result) {
        $fichadaId = (int) ($result['fichadaId'] ?? 0);
        $usuarioId = (string) ($result['usuarioId'] ?? '');
        if ($fichadaId <= 0 || $usuarioId === '') {
            continue;
        }
        $resultsByKey["{$fichadaId}|{$usuarioId}"] = $result;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO MovilFichadaPushLog
            (FichadaId, UsuarioId, EnviadoEn, Provider, ProviderMessageId, Plataforma, TokenSuffix, EstadoEnvio, ErrorEnvio)
         VALUES
            (:fichadaId, :usuarioId, :enviadoEn, :provider, :providerMessageId, :plataforma, :tokenSuffix, :estadoEnvio, :errorEnvio)
         ON DUPLICATE KEY UPDATE
            EnviadoEn = VALUES(EnviadoEn),
            Provider = VALUES(Provider),
            ProviderMessageId = VALUES(ProviderMessageId),
            Plataforma = VALUES(Plataforma),
            TokenSuffix = VALUES(TokenSuffix),
            EstadoEnvio = VALUES(EstadoEnvio),
            ErrorEnvio = VALUES(ErrorEnvio)'
    );
    $enviadoEn = (new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
        ->format('Y-m-d H:i:s');

    foreach ($logKeys as $key => $log) {
        $result = $resultsByKey[$key] ?? [];
        $accepted = ($result['accepted'] ?? false) === true;
        $stmt->execute([
            'fichadaId' => (int) $log['fichadaId'],
            'usuarioId' => (string) $log['usuarioId'],
            'enviadoEn' => $enviadoEn,
            'provider' => (string) ($result['provider'] ?? ''),
            'providerMessageId' => (string) ($result['messageId'] ?? ''),
            'plataforma' => (string) ($result['plataforma'] ?? ''),
            'tokenSuffix' => (string) ($result['tokenSuffix'] ?? ''),
            'estadoEnvio' => $accepted ? 'enviado' : 'error',
            'errorEnvio' => $accepted ? null : (string) ($result['error'] ?? 'No se pudo enviar el push'),
        ]);
    }
}

function fichadas_push_registrar_evento_usuario(PDO $pdo, string $usuarioId, int $fichadaId, string $evento): bool
{
    if ($fichadaId <= 0 || !in_array($evento, ['recibida', 'visualizada'], true)) {
        return false;
    }

    fichadas_push_ensure_log_table($pdo);

    $column = $evento === 'recibida' ? 'RecibidoEn' : 'VisualizadoEn';
    $fecha = (new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires')))
        ->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "UPDATE MovilFichadaPushLog
         SET {$column} = COALESCE({$column}, :fecha)
         WHERE FichadaId = :fichadaId
           AND UsuarioId = :usuarioId
           AND COALESCE(EstadoEnvio, 'enviado') = 'enviado'"
    );
    $stmt->execute([
        'fecha' => $fecha,
        'fichadaId' => $fichadaId,
        'usuarioId' => $usuarioId,
    ]);

    return $stmt->rowCount() > 0;
}
