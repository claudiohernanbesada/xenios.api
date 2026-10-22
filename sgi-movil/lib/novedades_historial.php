<?php
declare(strict_types=1);
require_once __DIR__ . '/inout_seguridad.php';

/** Solo destinatarios propios; conserva compatibilidad con registros anteriores a destinatarios múltiples. */
function novedades_historial_visitas_sql(): string
{
    return ' FROM InOutSeguridad i
        LEFT JOIN InOutSeguridadDestinatario d ON d.InOutSeguridadId = i.Id AND d.UsuarioId = ?
        LEFT JOIN Usuarios u ON u.Id = i.UserAdd
        WHERE i.Estado = 1 AND (d.UsuarioId IS NOT NULL OR (i.UsuarioVisitaId = ?
            AND NOT EXISTS (SELECT 1 FROM InOutSeguridadDestinatario x WHERE x.InOutSeguridadId = i.Id)))';
}

function novedades_historial_visitas(PDO $pdo, string $uid, ?string $desde = null, ?string $hasta = null, ?string $transaccion = null): array
{
    $sql = 'SELECT i.*, CASE WHEN d.UsuarioId IS NOT NULL THEN d.NotificacionLeidaEn ELSE i.NotificacionLeidaEn END AS LeidaEn,
        i.UserAdd AS EnviadoPorUsuarioId, u.Nombre AS EnvNombre, u.Apellido AS EnvApellido, u.Mail AS EnvMail'
        . novedades_historial_visitas_sql();
    $params = [$uid, $uid];
    if ($desde !== null && $hasta !== null) { $sql .= ' AND i.DateAdd >= ? AND i.DateAdd < ?'; array_push($params, $desde, $hasta); }
    if ($transaccion !== null) { $sql .= ' AND i.TransaccionId = ?'; $params[] = $transaccion; }
    $query = $pdo->prepare($sql . ' ORDER BY i.DateAdd, i.Id');
    $query->execute($params);
    $groups = [];
    while ($row = $query->fetch(PDO::FETCH_ASSOC)) $groups[$row['TransaccionId']][] = $row;
    $items = [];
    foreach ($groups as $id => $rows) {
        $notification = inout_notification($rows);
        $unread = array_filter($rows, static fn(array $row): bool => empty($row['LeidaEn']));
        $readDates = array_filter(array_column($rows, 'LeidaEn'));
        $items[] = [
            'id' => 'visita-' . $id, 'tipo' => 'visita', 'transaccionId' => $id,
            'titulo' => $notification['title'], 'cuerpo' => $notification['body'],
            'creadoEn' => novedades_format_datetime($rows[0]['DateAdd']),
            'leida' => $unread === [],
            'leidaEn' => $unread === [] && $readDates !== [] ? novedades_format_datetime(max($readDates)) : null,
        ] + novedades_remitente_desde_fila($rows[0]);
    }
    return $items;
}

function novedades_historial_mes(PDO $pdo, string $uid, int $anio, int $mes): array
{
    $desde = new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $anio, $mes), novedades_timezone());
    $hasta = $desde->modify('+1 month');
    $query = $pdo->prepare('SELECT n.Id, n.Titulo, n.Cuerpo, n.CreadoEn, n.EnviadoPorUsuarioId, nu.LeidaEn,
        u.Nombre AS EnvNombre, u.Apellido AS EnvApellido, u.Mail AS EnvMail
        FROM MovilNovedadUsuario nu INNER JOIN MovilNovedad n ON n.Id = nu.NovedadId
        LEFT JOIN Usuarios u ON u.Id = n.EnviadoPorUsuarioId
        WHERE nu.UsuarioId = ? AND n.CreadoEn >= ? AND n.CreadoEn < ?');
    $query->execute([$uid, $desde->format('Y-m-d H:i:s'), $hasta->format('Y-m-d H:i:s')]);
    $items = array_map(static fn(array $row): array => novedades_map_item($row) + ['tipo' => 'novedad'], $query->fetchAll(PDO::FETCH_ASSOC));
    $items = array_merge($items, novedades_historial_visitas($pdo, $uid, $desde->format('Y-m-d H:i:s'), $hasta->format('Y-m-d H:i:s')));
    usort($items, static fn(array $a, array $b): int => strcmp($b['creadoEn'], $a['creadoEn']) ?: strcmp($a['id'], $b['id']));
    $years = $pdo->prepare('SELECT DISTINCT YEAR(n.CreadoEn) AS anio FROM MovilNovedadUsuario nu
        INNER JOIN MovilNovedad n ON n.Id = nu.NovedadId WHERE nu.UsuarioId = ?
        UNION SELECT DISTINCT YEAR(i.DateAdd) AS anio' . novedades_historial_visitas_sql());
    $years->execute([$uid, $uid, $uid]);
    $anios = array_map('intval', $years->fetchAll(PDO::FETCH_COLUMN));
    $anios[] = (int) (new DateTimeImmutable('now', novedades_timezone()))->format('Y');
    $anios = array_values(array_unique($anios)); rsort($anios);
    return ['novedades' => $items, 'anio' => $anio, 'mes' => $mes, 'aniosDisponibles' => $anios];
}
