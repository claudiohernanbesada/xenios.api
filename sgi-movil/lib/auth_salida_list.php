<?php
declare(strict_types=1);

/**
 * Listado de autorizaciones de salida para la app móvil (horarios efectivos y fin de turno).
 */
require_once __DIR__ . '/asistencia_calendario.php';
require_once __DIR__ . '/horas_trabajadas_empleado.php';

/**
 * @param array<string, mixed> $personal Id, AreaNombre (o Area)
 * @return list<array<string, mixed>>
 */
function auth_salida_list_for_personal(PDO $pdo, array $personal): array
{
    $personalId = (string) ($personal['Id'] ?? '');
    if ($personalId === '') {
        return [];
    }

    $areaNombre = (string) ($personal['AreaNombre'] ?? $personal['Area'] ?? '');
    $turnos = asistencia_cargar_turnos($pdo);

    $stmt = $pdo->prepare(
        'SELECT a.Id, a.Fecha, a.HoraSalida, a.HoraRegreso,
                a.FechaHoraSalidaEfectiva, a.FechaHoraRegresoEfectiva,
                m.MotivoAuth, a.MotivoDetalle, a.AuthFile
         FROM AuthSalida a
         INNER JOIN AuthSalidaMotivos m ON m.Id = a.MotivoId
         WHERE a.Estado = 1 AND a.PersonalId = :pid
         ORDER BY a.Fecha DESC, a.HoraSalida DESC'
    );
    $stmt->execute(['pid' => $personalId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $lista = [];
    foreach ($rows as $row) {
        $lista[] = auth_salida_format_list_item($row, $turnos, $areaNombre);
    }

    return $lista;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function auth_salida_format_list_item(array $row, array $turnos, string $areaNombre): array
{
    $fechaRaw = (string) $row['Fecha'];
    $fechaFmt = $fechaRaw;
    $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $fechaRaw);
    if ($parsed) {
        $fechaFmt = $parsed->format('d/m/Y');
    }

    $authFile = $row['AuthFile'] ?? null;
    $tiene = $authFile !== null && $authFile !== '';

    $salidaEfectiva = auth_salida_format_datetime_hora($row['FechaHoraSalidaEfectiva'] ?? null);
    $regreso = auth_salida_resolve_regreso_efectiva($row, $turnos, $areaNombre);

    return [
        'id' => (string) $row['Id'],
        'fecha' => $fechaFmt,
        'horaSalida' => substr((string) $row['HoraSalida'], 0, 5),
        'horaRegreso' => substr((string) $row['HoraRegreso'], 0, 5),
        'motivo' => (string) $row['MotivoAuth'],
        'motivoDetalle' => isset($row['MotivoDetalle']) && $row['MotivoDetalle'] !== ''
            ? (string) $row['MotivoDetalle']
            : null,
        'horaSalidaEfectiva' => $salidaEfectiva,
        'horaRegresoEfectiva' => $regreso['hora'],
        'horaRegresoEfectivaFinTurno' => $regreso['finTurno'],
        'tieneArchivo' => $tiene,
        'nombreArchivo' => $tiene ? basename((string) $authFile) : null,
    ];
}

function auth_salida_format_datetime_hora(?string $value): ?string
{
    if ($value === null || trim($value) === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($value);
    } catch (Exception) {
        return null;
    }

    return $dt->format('H:i');
}

/**
 * @param array<string, mixed> $row
 * @return array{hora: ?string, finTurno: bool}
 */
function auth_salida_resolve_regreso_efectiva(array $row, array $turnos, string $areaNombre): array
{
    $regresoEfectivo = auth_salida_format_datetime_hora($row['FechaHoraRegresoEfectiva'] ?? null);
    if ($regresoEfectivo !== null) {
        return ['hora' => $regresoEfectivo, 'finTurno' => false];
    }

    $fechaRaw = (string) $row['Fecha'];
    $fecha = DateTimeImmutable::createFromFormat('Y-m-d', $fechaRaw);
    if ($fecha === false) {
        return ['hora' => null, 'finTurno' => false];
    }

    $hoy = new DateTimeImmutable('today');
    if ($fecha > $hoy) {
        return ['hora' => null, 'finTurno' => false];
    }

    if (!empty($row['FechaHoraSalidaEfectiva'])) {
        $salidaDt = new DateTimeImmutable((string) $row['FechaHoraSalidaEfectiva']);
        $salidaSec = (int) $salidaDt->format('H') * 3600 + (int) $salidaDt->format('i') * 60;
    } else {
        $salidaSec = ht_time_to_seconds(substr((string) $row['HoraSalida'], 0, 8));
    }

    $regresoSec = ht_time_to_seconds(substr((string) $row['HoraRegreso'], 0, 8));
    $finTurnoSec = ht_obtener_regreso_por_fin_de_turno(
        $turnos,
        $fecha,
        $salidaSec,
        $regresoSec,
        $areaNombre
    );

    if ($fecha < $hoy) {
        $hora = auth_salida_seconds_to_hora($finTurnoSec);
        return ['hora' => $hora, 'finTurno' => true];
    }

    $ahora = new DateTimeImmutable('now');
    $finTurnoDt = $fecha->setTime(
        intdiv($finTurnoSec, 3600),
        intdiv($finTurnoSec % 3600, 60)
    );
    if ($ahora >= $finTurnoDt) {
        return ['hora' => auth_salida_seconds_to_hora($finTurnoSec), 'finTurno' => true];
    }

    return ['hora' => null, 'finTurno' => false];
}

function auth_salida_seconds_to_hora(int $seconds): string
{
    return sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
}
