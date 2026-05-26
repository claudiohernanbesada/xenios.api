<?php
declare(strict_types=1);

/**
 * Vacaciones del empleado: días por antigüedad (LCT), consumidas (PersonalLicencias) y restantes.
 *
 * Las vacaciones consumidas son filas de PersonalLicencias cuyo motivo es «Vacaciones»
 * (PersonalLicenciasMotivo.Codigo = 'VAC', mismo criterio que RRHH y reporte de horas).
 */
require_once __DIR__ . '/horas_trabajadas_empleado.php';

/** Condición SQL: licencia cargada como vacaciones en el SGI. */
const VACACIONES_SQL_MOTIVO = "(UPPER(TRIM(m.Codigo)) = 'VAC' OR UPPER(TRIM(m.Motivo)) LIKE '%VACACION%')";

/** Días de vacaciones según años de antigüedad (LCT art. 150, al 31/12 del año). */
function vacaciones_dias_ley_por_antiguedad(int $anosCompletos): int
{
    if ($anosCompletos < 5) {
        return 14;
    }
    if ($anosCompletos < 10) {
        return 21;
    }
    if ($anosCompletos < 20) {
        return 28;
    }

    return 35;
}

function vacaciones_anos_antiguedad(DateTimeImmutable $fechaAlta, DateTimeImmutable $referencia): int
{
    $anos = (int) $referencia->format('Y') - (int) $fechaAlta->format('Y');
    if ($referencia < $fechaAlta->modify("+{$anos} years")) {
        $anos--;
    }

    return max(0, $anos);
}

/** Meses trabajados en un año calendario (desde FechaAlta o 1/1 hasta 31/12 o baja). */
function vacaciones_meses_en_anio(
    DateTimeImmutable $fechaAlta,
    ?DateTimeImmutable $fechaBaja,
    int $anio
): int {
    $inicioAnio = new DateTimeImmutable(sprintf('%d-01-01', $anio));
    $finAnio = new DateTimeImmutable(sprintf('%d-12-31', $anio));

    $desde = $fechaAlta > $inicioAnio ? $fechaAlta : $inicioAnio;
    $hasta = $finAnio;
    if ($fechaBaja !== null && $fechaBaja < $hasta) {
        $hasta = $fechaBaja;
    }

    if ($desde > $hasta) {
        return 0;
    }

    $mesDesde = (int) $desde->format('n');
    $mesHasta = (int) $hasta->format('n');

    return min(12, max(1, $mesHasta - $mesDesde + 1));
}

/**
 * Días de vacaciones que corresponden al año (antigüedad al 31/12; proporcional si ingresó en ese año).
 */
function vacaciones_dias_correspondientes(
    DateTimeImmutable $fechaAlta,
    ?DateTimeImmutable $fechaBaja,
    int $anio
): int {
    $ref = new DateTimeImmutable(sprintf('%d-12-31', $anio));
    if ($fechaAlta > $ref) {
        return 0;
    }
    if ($fechaBaja !== null && $fechaBaja < new DateTimeImmutable(sprintf('%d-01-01', $anio))) {
        return 0;
    }

    $anos = vacaciones_anos_antiguedad($fechaAlta, $ref);
    $base = vacaciones_dias_ley_por_antiguedad($anos);

    if ((int) $fechaAlta->format('Y') === $anio) {
        $meses = vacaciones_meses_en_anio($fechaAlta, $fechaBaja, $anio);
        if ($meses < 12) {
            return (int) round($base * $meses / 12);
        }
    }

    return $base;
}

/** @return list<int> */
function vacaciones_anios_disponibles(DateTimeImmutable $fechaAlta): array
{
    $anioAlta = (int) $fechaAlta->format('Y');
    $anioActual = (int) (new DateTimeImmutable('today'))->format('Y');
    $lista = [];
    for ($y = $anioAlta; $y <= $anioActual; $y++) {
        $lista[] = $y;
    }

    return $lista;
}

/**
 * Licencias de vacaciones del empleado en un año (PersonalLicencias + motivo Vacaciones).
 *
 * @return list<array{
 *   id: string,
 *   desde: string,
 *   hasta: string,
 *   dias: int,
 *   comentarios: string|null,
 *   codigoMotivo: string,
 *   motivo: string
 * }>
 */
function vacaciones_fetch_consumidas(PDO $pdo, string $personalId, int $anio): array
{
    if ($personalId === '') {
        return [];
    }

    $desdeAnio = sprintf('%d-01-01', $anio);
    $hastaAnio = sprintf('%d-12-31', $anio);
    $desdeDt = new DateTimeImmutable($desdeAnio);
    $hastaDt = new DateTimeImmutable($hastaAnio);

    $sql = 'SELECT pl.Id, pl.Desde, pl.Hasta, pl.Comentarios, m.Codigo AS CodigoMotivo, m.Motivo AS MotivoNombre
            FROM PersonalLicencias pl
            INNER JOIN PersonalLicenciasMotivo m ON m.Id = pl.PersonalLicenciasMotivoId
            WHERE pl.Estado = 1
              AND m.Estado = 1
              AND pl.PersonalId = :pid
              AND pl.Desde <= :hasta AND pl.Hasta >= :desde
              AND ' . VACACIONES_SQL_MOTIVO . '
            ORDER BY pl.Desde DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'pid' => $personalId,
        'desde' => $desdeAnio,
        'hasta' => $hastaAnio,
    ]);

    $consumidas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = new DateTimeImmutable((string) $row['Desde']);
        $h = new DateTimeImmutable((string) $row['Hasta']);
        $clipDesde = $d < $desdeDt ? $desdeDt : $d;
        $clipHasta = $h > $hastaDt ? $hastaDt : $h;

        $dias = ht_contar_dias_habiles($clipDesde, $clipHasta);
        if ($dias <= 0) {
            continue;
        }

        $consumidas[] = [
            'id' => (string) $row['Id'],
            'desde' => $clipDesde->format('Y-m-d'),
            'hasta' => $clipHasta->format('Y-m-d'),
            'dias' => $dias,
            'comentarios' => isset($row['Comentarios']) && $row['Comentarios'] !== ''
                ? (string) $row['Comentarios']
                : null,
            'codigoMotivo' => (string) ($row['CodigoMotivo'] ?? 'VAC'),
            'motivo' => (string) ($row['MotivoNombre'] ?? 'Vacaciones'),
        ];
    }

    return $consumidas;
}

/**
 * @param array<string, mixed> $personal Id, FechaAlta, FechaBaja (opcional)
 * @return array<string, mixed>
 */
function vacaciones_build_response(PDO $pdo, array $personal, int $anio): array
{
    $fechaAltaRaw = (string) ($personal['FechaAlta'] ?? '');
    if ($fechaAltaRaw === '') {
        throw new InvalidArgumentException('El empleado no tiene fecha de alta registrada');
    }

    $fechaAlta = new DateTimeImmutable(substr($fechaAltaRaw, 0, 10));
    $fechaBaja = null;
    if (!empty($personal['FechaBaja'])) {
        $fechaBaja = new DateTimeImmutable(substr((string) $personal['FechaBaja'], 0, 10));
    }

    $aniosDisponibles = vacaciones_anios_disponibles($fechaAlta);
    if (!in_array($anio, $aniosDisponibles, true)) {
        $anio = $aniosDisponibles === [] ? (int) date('Y') : $aniosDisponibles[array_key_last($aniosDisponibles)];
    }

    $ref = new DateTimeImmutable(sprintf('%d-12-31', $anio));
    $antiguedadAnios = vacaciones_anos_antiguedad($fechaAlta, $ref);
    $diasCorrespondientes = vacaciones_dias_correspondientes($fechaAlta, $fechaBaja, $anio);

    $personalId = (string) ($personal['Id'] ?? '');
    $consumidas = vacaciones_fetch_consumidas($pdo, $personalId, $anio);
    $diasConsumidos = 0;
    foreach ($consumidas as $lic) {
        $diasConsumidos += (int) $lic['dias'];
    }

    $diasRestantes = max(0, $diasCorrespondientes - $diasConsumidos);

    return [
        'anio' => $anio,
        'aniosDisponibles' => $aniosDisponibles,
        'fechaAlta' => $fechaAlta->format('Y-m-d'),
        'antiguedadAnios' => $antiguedadAnios,
        'diasCorrespondientes' => $diasCorrespondientes,
        'diasConsumidos' => $diasConsumidos,
        'diasRestantes' => $diasRestantes,
        'consumidas' => $consumidas,
    ];
}

/** @return array<string, mixed>|null */
function fetch_personal_vacaciones(PDO $pdo, string $mail): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.Id, p.Legajo, p.Nombre, p.Apellido, p.FechaAlta, p.FechaBaja
         FROM Personal p
         WHERE p.Estado IN (1, 2)
           AND (
             LOWER(TRIM(p.EmailCorporativo)) = LOWER(:mail)
             OR LOWER(TRIM(p.Email)) = LOWER(:mail)
           )
         LIMIT 1'
    );
    $stmt->execute(['mail' => strtolower(trim($mail))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}
