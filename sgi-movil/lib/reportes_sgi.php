<?php
declare(strict_types=1);

require_once __DIR__ . '/asistencia_calendario.php';
require_once __DIR__ . '/horas_trabajadas_empleado.php';

function reportes_sgi_parse_fecha(string $valor): ?DateTimeImmutable
{
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $valor)
        ?: DateTimeImmutable::createFromFormat('d/m/Y', $valor);
    return $dt instanceof DateTimeImmutable ? $dt : null;
}

function reportes_sgi_bool(mixed $value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

/** @return list<string> */
function reportes_sgi_area_ids_desde_query(): array
{
    $raw = trim((string) ($_GET['areaIds'] ?? ''));
    if ($raw === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $id): bool => $id !== ''));
}

/** @return list<array<string,mixed>> */
function reportes_sgi_empleados(
    PDO $pdo,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta,
    ?int $tipoContratacionId = null,
    ?string $sedeId = null,
    array $areaIds = []
): array {
    $where = [
        '(p.Estado = 1 OR (p.Estado = 2 AND (p.FechaBaja IS NULL OR p.FechaBaja >= :fechaInicio)))',
        'p.FechaAlta < :hastaExclusivo',
    ];
    $params = [
        'fechaInicio' => $desde->format('Y-m-d 00:00:00'),
        'hastaExclusivo' => $hasta->modify('+1 day')->format('Y-m-d 00:00:00'),
    ];

    if ($tipoContratacionId !== null && $tipoContratacionId > 0) {
        $where[] = 'p.TipoContratacionId = :tipoContratacionId';
        $params['tipoContratacionId'] = $tipoContratacionId;
    }
    if ($sedeId !== null && $sedeId !== '') {
        $where[] = 'p.DepositoId = :sedeId';
        $params['sedeId'] = $sedeId;
    }
    if ($areaIds !== []) {
        $placeholders = [];
        foreach ($areaIds as $idx => $areaId) {
            $key = 'area' . $idx;
            $placeholders[] = ':' . $key;
            $params[$key] = $areaId;
        }
        $where[] = 'p.ArealId IN (' . implode(',', $placeholders) . ')';
    }

    $sql = 'SELECT p.Id, p.Legajo, p.Nombre, p.Apellido, p.Foto, p.Estado, p.FechaBaja,
                   p.HorarioIngreso, p.HorarioSalida, p.HorasExtras, p.TipoContratacionId,
                   tc.NombreTipoContratacion, a.Nombre AS AreaNombre, d.Nombre AS Sede
            FROM Personal p
            LEFT JOIN TipoContratacion tc ON tc.Id = p.TipoContratacionId
            LEFT JOIN Area a ON a.Id = p.ArealId
            LEFT JOIN Depositos d ON d.Id = p.DepositoId
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY p.Apellido, p.Nombre';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<int,string> */
function reportes_sgi_feriados(PDO $pdo, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
{
    $stmt = $pdo->prepare(
        'SELECT Fecha, Descripcion FROM PersonalFeriados
         WHERE Estado = 1 AND Fecha >= :d AND Fecha <= :h'
    );
    $stmt->execute(['d' => $desde->format('Y-m-d'), 'h' => $hasta->format('Y-m-d')]);

    $feriados = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dt = new DateTimeImmutable((string) $row['Fecha']);
        $feriados[(int) $dt->format('j')] = (string) ($row['Descripcion'] ?? '');
    }
    return $feriados;
}

/** @return array<int,array{code:string,motivo:string}> */
function reportes_sgi_razones_ausencia(PDO $pdo, string $personalId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
{
    $stmt = $pdo->prepare(
        'SELECT pl.Desde, pl.Hasta, COALESCE(m.Codigo, "") AS Codigo, COALESCE(m.Motivo, "") AS Motivo
         FROM PersonalLicencias pl
         LEFT JOIN PersonalLicenciasMotivo m ON m.Id = pl.PersonalLicenciasMotivoId
         WHERE pl.Estado = 1 AND pl.PersonalId = :pid AND pl.Desde <= :h AND pl.Hasta >= :d'
    );
    $stmt->execute(['pid' => $personalId, 'd' => $desde->format('Y-m-d'), 'h' => $hasta->format('Y-m-d')]);

    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $desdeLicencia = new DateTimeImmutable((string) $row['Desde']);
        $hastaLicencia = new DateTimeImmutable((string) $row['Hasta']);
        $from = $desdeLicencia < $desde ? $desde : $desdeLicencia;
        $to = $hastaLicencia > $hasta ? $hasta : $hastaLicencia;
        for ($cur = $from; $cur <= $to; $cur = $cur->modify('+1 day')) {
            $result[(int) $cur->format('j')] = [
                'code' => (string) $row['Codigo'],
                'motivo' => (string) $row['Motivo'],
            ];
        }
    }
    return $result;
}

function reportes_sgi_asistencia(PDO $pdo, int $year, int $month, ?string $sedeId, array $areaIds): array
{
    $desde = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $hasta = $desde->modify('last day of this month');
    $empleados = reportes_sgi_empleados($pdo, $desde, $hasta, null, $sedeId, $areaIds);

    $items = [];
    foreach ($empleados as $emp) {
        $diasApi = asistencia_build_calendario($pdo, $emp, $desde->format('Y-m-d'), $hasta->format('Y-m-d'));
        $days = [];
        foreach ($diasApi as $dia) {
            $day = (int) substr((string) $dia['fecha'], 8, 2);
            $flags = is_array($dia['flags'] ?? null) ? $dia['flags'] : [];
            if (!empty($dia['entrada']) || !empty($dia['salida']) || reportes_sgi_bool($flags['usaAuthSalida'] ?? false)) {
                $days[$day] = [
                    'in' => (string) ($dia['entrada'] ?? ''),
                    'out' => (string) ($dia['salida'] ?? ''),
                    'esCargaManual' => reportes_sgi_bool($flags['esCargaManual'] ?? false),
                    'esCargaManualEntrada' => reportes_sgi_bool($flags['esCargaManualEntrada'] ?? false),
                    'esCargaManualSalida' => reportes_sgi_bool($flags['esCargaManualSalida'] ?? false),
                    'usaAuthSalida' => reportes_sgi_bool($flags['usaAuthSalida'] ?? false),
                ];
            }
        }

        $items[] = [
            'personalId' => (string) $emp['Id'],
            'legajo' => (string) $emp['Legajo'],
            'nombre' => trim((string) $emp['Apellido'] . ' ' . (string) $emp['Nombre']),
            'sede' => (string) ($emp['Sede'] ?? ''),
            'estado' => (int) ($emp['Estado'] ?? 1),
            'fechaBaja' => $emp['FechaBaja'] ? substr((string) $emp['FechaBaja'], 0, 10) : null,
            'days' => (object) $days,
            'razonAusencia' => (object) reportes_sgi_razones_ausencia($pdo, (string) $emp['Id'], $desde, $hasta),
        ];
    }

    return [
        'year' => $year,
        'month' => $month,
        'daysInMonth' => (int) $hasta->format('j'),
        'feriado' => (object) reportes_sgi_feriados($pdo, $desde, $hasta),
        'employees' => $items,
    ];
}

/** @return array<int,list<array<string,mixed>>> */
function reportes_sgi_fichadas_detalle(PDO $pdo, string $legajo, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
{
    if (trim($legajo) === '') {
        return [];
    }

    $legajoInt = (int) $legajo;
    $claves = function_exists('normalizar_legajo_claves')
        ? normalizar_legajo_claves($legajoInt)
        : [$legajo, str_pad((string) $legajoInt, 4, '0', STR_PAD_LEFT)];
    $placeholders = implode(',', array_fill(0, count($claves), '?'));
    $stmt = $pdo->prepare(
        "SELECT f.Fecha, f.EsCargaManual, COALESCE(m.Motivo, '') AS MotivoManual
         FROM FichadasGlobal f
         LEFT JOIN FichadaGlobalManualMotivos m ON m.Id = f.MotivoId
         WHERE f.Estado = 1 AND f.Fecha >= ? AND f.Fecha <= ? AND f.Legajo IN ($placeholders)
         ORDER BY f.Fecha ASC"
    );
    $stmt->execute(array_merge([$desde->format('Y-m-d 00:00:00'), $hasta->format('Y-m-d 23:59:59')], $claves));

    $porDia = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fecha = new DateTimeImmutable((string) $row['Fecha']);
        $porDia[(int) $fecha->format('j')][] = [
            'fecha' => $fecha,
            'esManual' => (bool) $row['EsCargaManual'],
            'motivoManual' => (string) ($row['MotivoManual'] ?? ''),
        ];
    }
    return $porDia;
}

/** @return array<int,list<array<string,mixed>>> */
function reportes_sgi_auth_detalle(PDO $pdo, string $personalId, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
{
    if ($personalId === '') {
        return [];
    }

    $stmt = $pdo->prepare(
        'SELECT a.Fecha, a.HoraSalida, a.FechaRegreso, a.HoraRegreso,
                a.FechaHoraSalidaEfectiva, a.FechaHoraRegresoEfectiva,
                COALESCE(m.MotivoAuth, "") AS Motivo
         FROM AuthSalida a
         LEFT JOIN AuthSalidaMotivos m ON m.Id = a.MotivoId
         WHERE a.Estado = 1 AND a.PersonalId = :pid AND a.Fecha <= :h AND a.FechaRegreso >= :d
         ORDER BY a.Fecha ASC, a.HoraSalida ASC'
    );
    $stmt->execute(['pid' => $personalId, 'd' => $desde->format('Y-m-d'), 'h' => $hasta->format('Y-m-d')]);

    $porDia = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fecha = new DateTimeImmutable((string) $row['Fecha']);
        $porDia[(int) $fecha->format('j')][] = [
            'fechaSalida' => substr((string) $row['Fecha'], 0, 10),
            'horaSalida' => substr((string) $row['HoraSalida'], 0, 5),
            'fechaRegreso' => substr((string) $row['FechaRegreso'], 0, 10),
            'horaRegreso' => substr((string) $row['HoraRegreso'], 0, 5),
            'salidaEfectiva' => $row['FechaHoraSalidaEfectiva'] !== null ? (string) $row['FechaHoraSalidaEfectiva'] : null,
            'regresoEfectivo' => $row['FechaHoraRegresoEfectiva'] !== null ? (string) $row['FechaHoraRegresoEfectiva'] : null,
            'motivo' => (string) ($row['Motivo'] ?? ''),
        ];
    }
    return $porDia;
}

function reportes_sgi_redondear_hora_arriba(string $hora): string
{
    if (!preg_match('/^(\d{2}):(\d{2})$/', $hora, $m)) {
        return '';
    }
    $h = (int) $m[1];
    $min = (int) $m[2];
    if ($min > 0) {
        $h += 1;
    }
    return sprintf('%02d:00', $h % 24);
}

function reportes_sgi_asistencia_detallada(PDO $pdo, int $year, int $month, ?string $sedeId, array $areaIds): array
{
    $desde = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
    $hasta = $desde->modify('last day of this month');
    $base = reportes_sgi_asistencia($pdo, $year, $month, $sedeId, $areaIds);
    $feriadosBase = is_array($base['feriado'] ?? null) ? $base['feriado'] : (array) ($base['feriado'] ?? []);
    $hoy = new DateTimeImmutable('today');

    $employees = [];
    foreach ($base['employees'] as $emp) {
        $fichadasPorDia = reportes_sgi_fichadas_detalle($pdo, (string) $emp['legajo'], $desde, $hasta);
        $authPorDia = reportes_sgi_auth_detalle($pdo, (string) $emp['personalId'], $desde, $hasta);
        $daysBase = is_array($emp['days'] ?? null) ? $emp['days'] : (array) ($emp['days'] ?? []);
        $ausenciasBase = is_array($emp['razonAusencia'] ?? null) ? $emp['razonAusencia'] : (array) ($emp['razonAusencia'] ?? []);
        $detailDays = [];

        for ($day = 1; $day <= (int) $base['daysInMonth']; $day++) {
            $dia = $desde->setDate($year, $month, $day);
            $dayBase = $daysBase[$day] ?? null;
            $dayInfo = [
                'entradaCalculada' => $dayBase['in'] ?? '',
                'salidaCalculada' => $dayBase['out'] ?? '',
                'entradaCalculoHoras' => '',
                'salidaCalculoHoras' => '',
                'minutosCalculoHoras' => 0,
                'minutosHorasNormales' => 0,
                'minutosHorasExtras' => 0,
                'usaAuthSalida' => reportes_sgi_bool($dayBase['usaAuthSalida'] ?? false),
                'tieneFichadaManual' => reportes_sgi_bool($dayBase['esCargaManual'] ?? false),
                'estadoCodigo' => '',
                'estadoDia' => '',
                'fichadas' => [],
                'autorizaciones' => $authPorDia[$day] ?? [],
            ];

            if ($dayInfo['entradaCalculada'] !== '' && $dayInfo['salidaCalculada'] !== '') {
                $entradaCalc = reportes_sgi_redondear_hora_arriba((string) $dayInfo['entradaCalculada']);
                $dayInfo['entradaCalculoHoras'] = $entradaCalc;
                $dayInfo['salidaCalculoHoras'] = (string) $dayInfo['salidaCalculada'];
                $ini = DateTimeImmutable::createFromFormat('Y-m-d H:i', $dia->format('Y-m-d') . ' ' . $entradaCalc);
                $fin = DateTimeImmutable::createFromFormat('Y-m-d H:i', $dia->format('Y-m-d') . ' ' . (string) $dayInfo['salidaCalculada']);
                if ($ini instanceof DateTimeImmutable && $fin instanceof DateTimeImmutable) {
                    if ($fin <= $ini) {
                        $fin = $fin->modify('+1 day');
                    }
                    $mins = max(0, (int) (($fin->getTimestamp() - $ini->getTimestamp()) / 60));
                    $dayInfo['minutosCalculoHoras'] = $mins;
                    $dayInfo['minutosHorasNormales'] = $mins;
                }
            }

            $fichadasDia = $fichadasPorDia[$day] ?? [];
            $noManuales = array_values(array_filter($fichadasDia, static fn (array $f): bool => !$f['esManual']));
            for ($i = 0; $i < count($noManuales); $i += 2) {
                $desdeFicha = $noManuales[$i]['fecha'];
                $hastaFicha = $noManuales[$i + 1]['fecha'] ?? null;
                $dayInfo['fichadas'][] = [
                    'horaDesde' => $desdeFicha instanceof DateTimeImmutable ? $desdeFicha->format('H:i') : '',
                    'horaHasta' => $hastaFicha instanceof DateTimeImmutable ? $hastaFicha->format('H:i') : '',
                    'esManual' => false,
                    'motivoManual' => '',
                ];
            }
            foreach (array_filter($fichadasDia, static fn (array $f): bool => $f['esManual']) as $f) {
                $fechaFicha = $f['fecha'];
                $dayInfo['fichadas'][] = [
                    'horaDesde' => $fechaFicha instanceof DateTimeImmutable ? $fechaFicha->format('H:i') : '',
                    'horaHasta' => '',
                    'esManual' => true,
                    'motivoManual' => (string) ($f['motivoManual'] ?? ''),
                ];
                $dayInfo['tieneFichadaManual'] = true;
            }

            $ausencia = $ausenciasBase[$day] ?? null;
            if (is_array($ausencia) && trim((string) ($ausencia['motivo'] ?? '')) !== '') {
                $dayInfo['estadoCodigo'] = trim((string) ($ausencia['code'] ?? '')) !== '' ? (string) $ausencia['code'] : 'AUSENCIA';
                $dayInfo['estadoDia'] = (string) $ausencia['motivo'];
            } elseif ($dayInfo['entradaCalculada'] === '' && $dayInfo['salidaCalculada'] === '' && isset($feriadosBase[$day])) {
                $dayInfo['estadoCodigo'] = 'FERIADO';
                $dayInfo['estadoDia'] = 'Feriado: ' . (string) $feriadosBase[$day];
            } elseif ($dayInfo['entradaCalculada'] === '' && $dayInfo['salidaCalculada'] === '' && $dia <= $hoy && !in_array((int) $dia->format('w'), [0, 6], true)) {
                $fechaBaja = !empty($emp['fechaBaja']) ? new DateTimeImmutable((string) $emp['fechaBaja']) : null;
                if ((int) $emp['estado'] === 1 || ((int) $emp['estado'] === 2 && $fechaBaja !== null && $dia < $fechaBaja)) {
                    $dayInfo['estadoCodigo'] = 'NI';
                    $dayInfo['estadoDia'] = 'N/I';
                } else {
                    $dayInfo['estadoCodigo'] = 'BAJA';
                    $dayInfo['estadoDia'] = 'BAJA';
                }
            }

            if ($dayInfo['entradaCalculada'] !== '' || $dayInfo['salidaCalculada'] !== '' || $dayInfo['fichadas'] !== [] || $dayInfo['autorizaciones'] !== [] || $dayInfo['estadoDia'] !== '') {
                $detailDays[$day] = $dayInfo;
            }
        }

        $employees[] = [
            'personalId' => $emp['personalId'],
            'legajo' => $emp['legajo'],
            'nombre' => $emp['nombre'],
            'sede' => $emp['sede'],
            'days' => (object) $detailDays,
        ];
    }

    return [
        'year' => $year,
        'month' => $month,
        'daysInMonth' => (int) $base['daysInMonth'],
        'employees' => $employees,
    ];
}

function reportes_sgi_horas_trabajadas(
    PDO $pdo,
    string $fechaInicio,
    string $fechaFin,
    ?int $tipoContratacionId,
    array $areaIds,
    array $config
): array {
    $desde = reportes_sgi_parse_fecha($fechaInicio);
    $hasta = reportes_sgi_parse_fecha($fechaFin);
    if ($desde === null || $hasta === null || $desde > $hasta) {
        return ['empleados' => []];
    }

    $empleados = reportes_sgi_empleados($pdo, $desde, $hasta, $tipoContratacionId, null, $areaIds);
    $rows = [];
    foreach ($empleados as $emp) {
        $resumen = horas_trabajadas_build_resumen($pdo, $emp, $desde->format('Y-m-d'), $hasta->format('Y-m-d'), $config);
        $rows[] = $resumen;
    }

    usort($rows, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['apellido'] ?? ''), (string) ($b['apellido'] ?? ''))
            ?: strcasecmp((string) ($a['nombre'] ?? ''), (string) ($b['nombre'] ?? ''));
    });

    return [
        'fechaInicio' => $desde->format('Y-m-d'),
        'fechaFin' => $hasta->format('Y-m-d'),
        'empleados' => $rows,
    ];
}
