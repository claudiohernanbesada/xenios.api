<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Calendario de asistencia de un empleado entre dos fechas (lógica ReportsController, un personal).
 *
 * @param array<string, mixed> $personal Id, Legajo, Nombre, Apellido, Estado, FechaBaja, AreaNombre (o Area)
 * @return list<array<string, mixed>>
 */
function asistencia_build_calendario(PDO $pdo, array $personal, string $desde, string $hasta): array
{
    $desdeDt = DateTimeImmutable::createFromFormat('!Y-m-d', $desde);
    $hastaDt = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta);
    if ($desdeDt === false || $hastaDt === false || $desdeDt > $hastaDt) {
        return [];
    }

    $personalId = (string) ($personal['Id'] ?? '');
    $legajo = (int) ($personal['Legajo'] ?? 0);
    $estadoEmpleado = (int) ($personal['Estado'] ?? 1);
    $areaNombre = (string) ($personal['AreaNombre'] ?? $personal['areaNombre'] ?? $personal['Area'] ?? '');
    $fechaBaja = asistencia_parse_fecha_baja($personal['FechaBaja'] ?? null);

    $hoy = new DateTimeImmutable('today');
    $finConsulta = $hastaDt->setTime(23, 59, 59);
    if ($finConsulta > $hoy->setTime(23, 59, 59)) {
        $finConsulta = $hoy->setTime(23, 59, 59);
    }

    $turnos = asistencia_cargar_turnos($pdo);
    $fichadas = asistencia_cargar_fichadas($pdo, $legajo, $desdeDt, $finConsulta);
    $authPorFecha = asistencia_cargar_auth_salida($pdo, $personalId, $desdeDt, $hastaDt);
    $licenciasPorDia = asistencia_cargar_licencias_por_dia($pdo, $personalId, $desdeDt, $hastaDt);
    $feriados = asistencia_cargar_feriados($pdo, $desdeDt, $hastaDt);

    $areasDict = [$personalId => $areaNombre];
    $diasMap = asistencia_agrupar_fichadas_por_dia(
        $fichadas,
        $turnos,
        $personalId,
        $areasDict,
        $authPorFecha
    );

    $resultado = [];
    for ($cur = $desdeDt; $cur <= $hastaDt; $cur = $cur->modify('+1 day')) {
        $resultado[] = asistencia_armar_dia(
            $cur,
            $hoy,
            $estadoEmpleado,
            $fechaBaja,
            $diasMap,
            $licenciasPorDia,
            $feriados
        );
    }

    return $resultado;
}

/** @return array<string, mixed> */
function asistencia_armar_dia(
    DateTimeImmutable $dia,
    DateTimeImmutable $hoy,
    int $estadoEmpleado,
    ?DateTimeImmutable $fechaBaja,
    array $diasMap,
    array $licenciasPorDia,
    array $feriados
): array {
    $fechaStr = $dia->format('Y-m-d');
    $dow = (int) $dia->format('w'); // 0=Dom … 6=Sab
    $esFinDeSemana = $dow === 0 || $dow === 6;

    $flags = [
        'esCargaManualEntrada' => false,
        'esCargaManualSalida' => false,
        'usaAuthSalida' => false,
        'esCargaManual' => false,
    ];

    if (isset($diasMap[$fechaStr])) {
        $val = $diasMap[$fechaStr];
        $entrada = $val['entrada'];
        $salida = $val['salida'];
        $flags['esCargaManualEntrada'] = $val['esCargaManualEntrada'];
        $flags['esCargaManualSalida'] = $val['esCargaManualSalida'];
        $flags['usaAuthSalida'] = $val['usaAuthSalida'];
        $flags['esCargaManual'] = $val['esCargaManual'];

        $estado = 'presente';
        if ($entrada === null || $entrada === '') {
            $estado = 'pendiente_entrada';
        } elseif ($salida === null || $salida === '') {
            $estado = 'pendiente_salida';
        }

        return [
            'fecha' => $fechaStr,
            'entrada' => $entrada !== '' ? $entrada : null,
            'salida' => $salida !== '' ? $salida : null,
            'estado' => $estado,
            'codigo' => null,
            'motivo' => null,
            'flags' => $flags,
        ];
    }

    if (isset($licenciasPorDia[$fechaStr])) {
        $lic = $licenciasPorDia[$fechaStr];
        return [
            'fecha' => $fechaStr,
            'entrada' => null,
            'salida' => null,
            'estado' => 'licencia',
            'codigo' => $lic['codigo'],
            'motivo' => $lic['motivo'],
            'flags' => $flags,
        ];
    }

    if (isset($feriados[$fechaStr])) {
        return [
            'fecha' => $fechaStr,
            'entrada' => null,
            'salida' => null,
            'estado' => 'feriado',
            'codigo' => 'F',
            'motivo' => $feriados[$fechaStr],
            'flags' => $flags,
        ];
    }

    if ($esFinDeSemana && $estadoEmpleado === 1) {
        return [
            'fecha' => $fechaStr,
            'entrada' => null,
            'salida' => null,
            'estado' => 'fin_de_semana',
            'codigo' => null,
            'motivo' => null,
            'flags' => $flags,
        ];
    }

    if ($dia > $hoy && $estadoEmpleado === 1) {
        return [
            'fecha' => $fechaStr,
            'entrada' => null,
            'salida' => null,
            'estado' => 'futuro',
            'codigo' => null,
            'motivo' => null,
            'flags' => $flags,
        ];
    }

    if ($estadoEmpleado === 1 || ($estadoEmpleado === 2 && $fechaBaja !== null && $dia < $fechaBaja)) {
        return [
            'fecha' => $fechaStr,
            'entrada' => null,
            'salida' => null,
            'estado' => 'no_informa',
            'codigo' => 'N/I',
            'motivo' => 'No informa',
            'flags' => $flags,
        ];
    }

    return [
        'fecha' => $fechaStr,
        'entrada' => null,
        'salida' => null,
        'estado' => 'baja',
        'codigo' => 'BAJA',
        'motivo' => 'Baja de Empleado',
        'flags' => $flags,
    ];
}

/** @return list<array{Entrada: string, Salida: string}> */
function asistencia_cargar_turnos(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT Entrada, Salida FROM Turnos WHERE Estado = 1'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $turnos = [];
    foreach ($rows as $row) {
        $turnos[] = [
            'Entrada' => substr((string) $row['Entrada'], 0, 8),
            'Salida' => substr((string) $row['Salida'], 0, 8),
        ];
    }
    return $turnos;
}

/** @return list<array{Fecha: DateTimeImmutable, EsCargaManual: bool}> */
function asistencia_cargar_fichadas(
    PDO $pdo,
    int $legajo,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta
): array {
    if ($legajo <= 0) {
        return [];
    }
    $claves = normalizar_legajo_claves($legajo);
    $placeholders = implode(',', array_fill(0, count($claves), '?'));
    $sql = "SELECT Fecha, EsCargaManual FROM FichadasGlobal
            WHERE Estado = 1
              AND Fecha >= ?
              AND Fecha <= ?
              AND Legajo IN ($placeholders)
            ORDER BY Fecha ASC";
    $params = array_merge(
        [$desde->format('Y-m-d 00:00:00'), $hasta->format('Y-m-d H:i:s')],
        $claves
    );
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dt = new DateTimeImmutable((string) $row['Fecha']);
        $out[] = [
            'Fecha' => $dt,
            'EsCargaManual' => (bool) $row['EsCargaManual'],
        ];
    }
    return $out;
}

/** @return array<string, array<string, mixed>> keyed by Y-m-d */
function asistencia_cargar_auth_salida(
    PDO $pdo,
    string $personalId,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta
): array {
    if ($personalId === '') {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT Fecha, HoraSalida, HoraRegreso, FechaHoraSalidaEfectiva, FechaHoraRegresoEfectiva
         FROM AuthSalida
         WHERE Estado = 1 AND PersonalId = :pid AND Fecha >= :desde AND Fecha <= :hasta'
    );
    $stmt->execute([
        'pid' => $personalId,
        'desde' => $desde->format('Y-m-d'),
        'hasta' => $hasta->format('Y-m-d'),
    ]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string) $row['Fecha']] = $row;
    }
    return $map;
}

/** @return array<string, array{codigo: string, motivo: string}> keyed by Y-m-d */
function asistencia_cargar_licencias_por_dia(
    PDO $pdo,
    string $personalId,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta
): array {
    if ($personalId === '') {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT pl.Desde, pl.Hasta, m.Codigo, m.Motivo
         FROM PersonalLicencias pl
         INNER JOIN PersonalLicenciasMotivo m ON m.Id = pl.PersonalLicenciasMotivoId
         WHERE pl.Estado = 1
           AND pl.PersonalId = :pid
           AND pl.Desde <= :hasta
           AND pl.Hasta >= :desde'
    );
    $stmt->execute([
        'pid' => $personalId,
        'desde' => $desde->format('Y-m-d'),
        'hasta' => $hasta->format('Y-m-d'),
    ]);
    $porDia = [];
    $desdeOnly = DateTimeImmutable::createFromFormat('!Y-m-d', $desde->format('Y-m-d'));
    $hastaOnly = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta->format('Y-m-d'));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $from = max(
            new DateTimeImmutable((string) $row['Desde']),
            $desdeOnly
        );
        $to = min(
            new DateTimeImmutable((string) $row['Hasta']),
            $hastaOnly
        );
        $codigo = (string) ($row['Codigo'] ?? '');
        $motivo = (string) ($row['Motivo'] ?? '');
        for ($cur = $from; $cur <= $to; $cur = $cur->modify('+1 day')) {
            $porDia[$cur->format('Y-m-d')] = [
                'codigo' => $codigo,
                'motivo' => $motivo,
            ];
        }
    }
    return $porDia;
}

/** @return array<string, string> Y-m-d => descripcion */
function asistencia_cargar_feriados(
    PDO $pdo,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta
): array {
    $stmt = $pdo->prepare(
        'SELECT Fecha, Descripcion FROM PersonalFeriados
         WHERE Estado = 1 AND Fecha >= :desde AND Fecha <= :hasta'
    );
    $stmt->execute([
        'desde' => $desde->format('Y-m-d'),
        'hasta' => $hasta->format('Y-m-d'),
    ]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(string) $row['Fecha']] = (string) $row['Descripcion'];
    }
    return $map;
}

/**
 * Agrupa fichadas por fecha y aplica ClasificarFichadasPorTurno + AuthSalida.
 *
 * @param list<array{Fecha: DateTimeImmutable, EsCargaManual: bool}> $fichadas
 * @param list<array{Entrada: string, Salida: string}> $turnos
 * @return array<string, array<string, mixed>> keyed by Y-m-d
 */
function asistencia_agrupar_fichadas_por_dia(
    array $fichadas,
    array $turnos,
    string $personalId,
    array $areasDict,
    array $authPorFecha
): array {
    $porDia = [];
    foreach ($fichadas as $f) {
        $fechaKey = $f['Fecha']->format('Y-m-d');
        $porDia[$fechaKey][] = $f;
    }

    $map = [];
    foreach ($porDia as $fechaKey => $lista) {
        usort($lista, static fn ($a, $b) => $a['Fecha'] <=> $b['Fecha']);
        $pares = array_map(
            static fn ($x) => ['Fecha' => $x['Fecha'], 'EsCargaManual' => $x['EsCargaManual']],
            $lista
        );
        $clasificacion = asistencia_clasificar_fichadas_por_turno($pares, $turnos, $personalId, $areasDict);

        $entradaDt = $clasificacion['Entrada'];
        $salidaDt = $clasificacion['Salida'];
        $usaAuthSalida = false;

        $authSalida = $authPorFecha[$fechaKey] ?? null;
        if ($authSalida !== null
            && count($lista) === 1
            && $entradaDt === null
            && $salidaDt !== null
            && !empty($authSalida['FechaHoraSalidaEfectiva'])
            && empty($authSalida['FechaHoraRegresoEfectiva'])
        ) {
            $salidaEfectivaAuth = new DateTimeImmutable((string) $authSalida['FechaHoraSalidaEfectiva']);
            if ($lista[0]['Fecha'] < $salidaEfectivaAuth) {
                $entradaDt = $lista[0]['Fecha'];
                $salidaDt = null;
                $clasificacion['EsManualEntrada'] = (bool) $lista[0]['EsCargaManual'];
                $clasificacion['EsManualSalida'] = false;
            }
        }

        $hayFichadasPosterioresAuth = false;
        if ($authSalida !== null && $entradaDt !== null) {
            $limitePosterior = asistencia_referencia_limite_posterior_auth(
                $authSalida,
                $turnos,
                $entradaDt,
                $personalId,
                $areasDict
            );
            foreach ($lista as $f) {
                if ($f['Fecha'] > $limitePosterior) {
                    $hayFichadasPosterioresAuth = true;
                    break;
                }
            }
        }

        $tieneSalidaEfectivaSinRegreso = $authSalida !== null
            && $entradaDt !== null
            && !empty($authSalida['FechaHoraSalidaEfectiva'])
            && empty($authSalida['FechaHoraRegresoEfectiva']);

        if ($tieneSalidaEfectivaSinRegreso && !$hayFichadasPosterioresAuth) {
            if (!empty($authSalida['FechaHoraRegresoEfectiva'])) {
                $reg = new DateTimeImmutable((string) $authSalida['FechaHoraRegresoEfectiva']);
                $horarioRegreso = $reg->format('H:i:s');
            } else {
                $horarioRegreso = asistencia_get_turno_salida(
                    $turnos,
                    $entradaDt,
                    $personalId,
                    $areasDict
                );
            }
            $salidaDt = DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $entradaDt->format('Y-m-d') . ' ' . substr($horarioRegreso, 0, 8)
            ) ?: $entradaDt;
            $usaAuthSalida = true;
        }

        $esManual = false;
        foreach ($lista as $f) {
            if ($f['EsCargaManual']) {
                $esManual = true;
                break;
            }
        }

        $map[$fechaKey] = [
            'entrada' => $entradaDt !== null ? $entradaDt->format('H:i') : '',
            'salida' => $salidaDt !== null ? $salidaDt->format('H:i') : '',
            'esCargaManualEntrada' => $clasificacion['EsManualEntrada'],
            'esCargaManualSalida' => $clasificacion['EsManualSalida'],
            'usaAuthSalida' => $usaAuthSalida,
            'esCargaManual' => $esManual,
        ];
    }

    return $map;
}

/**
 * Hasta cuándo se toleran fichadas sin invalidar la imputación de salida por auth.
 * Con salida efectiva y sin regreso, el tope es la hora de regreso autorizada (o fin de turno),
 * no la salida efectiva (evita bloquear por la propia fichada de salida).
 *
 * @param list<array{Entrada: string, Salida: string}> $turnos
 */
function asistencia_referencia_limite_posterior_auth(
    array $authSalida,
    array $turnos,
    DateTimeImmutable $entradaDt,
    string $personalId,
    array $areasDict
): DateTimeImmutable {
    if (!empty($authSalida['FechaHoraRegresoEfectiva'])) {
        return new DateTimeImmutable((string) $authSalida['FechaHoraRegresoEfectiva']);
    }
    $fecha = (string) $authSalida['Fecha'];
    if (!empty($authSalida['HoraRegreso'])) {
        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $fecha . ' ' . substr((string) $authSalida['HoraRegreso'], 0, 8)
        );
        if ($dt !== false) {
            return $dt;
        }
    }
    $horarioRegreso = asistencia_get_turno_salida(
        $turnos,
        $entradaDt,
        $personalId,
        $areasDict
    );
    $dt = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $fecha . ' ' . substr($horarioRegreso, 0, 8)
    );
    if ($dt !== false) {
        return $dt;
    }
    $hora = substr((string) ($authSalida['HoraSalida'] ?? '23:59:59'), 0, 8);
    return new DateTimeImmutable($fecha . ' ' . $hora);
}

/** @param list<array{Fecha: DateTimeImmutable, EsCargaManual: bool}> $fichadas */
function asistencia_clasificar_fichadas_por_turno(
    array $fichadas,
    array $turnos,
    string $personaId,
    array $areasDict
): array {
    usort($fichadas, static fn ($a, $b) => $a['Fecha'] <=> $b['Fecha']);
    if ($fichadas === []) {
        return [
            'Entrada' => null,
            'Salida' => null,
            'EsManualEntrada' => false,
            'EsManualSalida' => false,
        ];
    }

    if (count($fichadas) > 1) {
        $entrada = $fichadas[0];
        $salida = $fichadas[count($fichadas) - 1];
        $diferenciaMinutos = ($salida['Fecha']->getTimestamp() - $entrada['Fecha']->getTimestamp()) / 60.0;

        return [
            'Entrada' => $entrada['Fecha'],
            'Salida' => $diferenciaMinutos >= 5 ? $salida['Fecha'] : null,
            'EsManualEntrada' => $entrada['EsCargaManual'],
            'EsManualSalida' => $diferenciaMinutos >= 5 && $salida['EsCargaManual'],
        ];
    }

    $entradas = [];
    $salidas = [];
    foreach ($fichadas as $fichada) {
        if (asistencia_es_fichada_mas_cercana_a_salida($fichada['Fecha'], $turnos, $personaId, $areasDict)) {
            $salidas[] = $fichada;
        } else {
            $entradas[] = $fichada;
        }
    }

    $entrada = $entradas !== [] ? $entradas[0] : null;
    $salida = $salidas !== [] ? $salidas[count($salidas) - 1] : null;

    return [
        'Entrada' => $entrada !== null ? $entrada['Fecha'] : null,
        'Salida' => $salida !== null ? $salida['Fecha'] : null,
        'EsManualEntrada' => $entrada !== null && $entrada['EsCargaManual'],
        'EsManualSalida' => $salida !== null && $salida['EsCargaManual'],
    ];
}

function asistencia_es_fichada_mas_cercana_a_salida(
    DateTimeImmutable $fichada,
    array $turnos,
    string $personaId,
    array $areasDict
): bool {
    if ((int) $fichada->format('w') === 6) {
        $hora = asistencia_time_to_minutes($fichada->format('H:i:s'));
        $distEntrada = abs(asistencia_time_to_minutes('08:00:00') - $hora);
        $distSalida = abs(asistencia_time_to_minutes('12:00:00') - $hora);
        return $distSalida < $distEntrada;
    }

    $esLogistica = ($areasDict[$personaId] ?? '') === 'LOGISTICA';
    $hora = asistencia_time_to_minutes($fichada->format('H:i:s'));
    $esViernes = (int) $fichada->format('w') === 5;

    $menorEntrada = PHP_FLOAT_MAX;
    $menorSalida = PHP_FLOAT_MAX;
    $entradaMasCercana = null;
    $salidaMasCercana = null;

    foreach ($turnos as $turno) {
        $horario = asistencia_obtener_horario_evaluacion($turno, $esViernes);
        $entradaTurno = $horario['Entrada'];
        $salidaTurno = $horario['Salida'];

        if (!$esLogistica
            && $turno['Entrada'] === '14:00:00'
            && $turno['Salida'] === '23:00:00'
        ) {
            $entradaTurno = $esViernes ? '14:00:00' : '15:00:00';
            $salidaTurno = $esViernes ? '22:00:00' : '23:45:00';
        }

        $distEntrada = abs(asistencia_time_to_minutes($entradaTurno) - $hora);
        if ($distEntrada < $menorEntrada) {
            $menorEntrada = $distEntrada;
            $entradaMasCercana = $entradaTurno;
        }

        $distSalida = abs(asistencia_time_to_minutes($salidaTurno) - $hora);
        if ($distSalida < $menorSalida) {
            $menorSalida = $distSalida;
            $salidaMasCercana = $salidaTurno;
        }
    }

    if ($menorSalida < $menorEntrada) {
        return true;
    }
    if ($menorEntrada < $menorSalida) {
        return false;
    }
    if ($entradaMasCercana !== null && $salidaMasCercana !== null && $entradaMasCercana === $salidaMasCercana) {
        return $hora >= asistencia_time_to_minutes($salidaMasCercana);
    }

    return false;
}

function asistencia_get_turno_salida(
    array $turnos,
    DateTimeImmutable $horaEntrada,
    string $personaId,
    array $areasDict
): string {
    if ((int) $horaEntrada->format('w') === 6) {
        return '12:00:00';
    }

    $esLogistica = ($areasDict[$personaId] ?? '') === 'LOGISTICA';
    $horaEntradaMin = asistencia_time_to_minutes($horaEntrada->format('H:i:s'));
    $esViernes = (int) $horaEntrada->format('w') === 5;

    $turnoMasCercano = null;
    $menorDist = PHP_FLOAT_MAX;
    foreach ($turnos as $turno) {
        $horario = asistencia_obtener_horario_evaluacion($turno, $esViernes);
        $dist = abs(asistencia_time_to_minutes($horario['Entrada']) - $horaEntradaMin);
        if ($dist < $menorDist) {
            $menorDist = $dist;
            $turnoMasCercano = $turno;
        }
    }

    if ($turnoMasCercano === null) {
        return $horaEntrada->format('H:i:s');
    }

    if (!$esLogistica
        && $turnoMasCercano['Entrada'] === '14:00:00'
        && $turnoMasCercano['Salida'] === '23:00:00'
    ) {
        return $esViernes ? '22:00:00' : '23:45:00';
    }

    $horario = asistencia_obtener_horario_evaluacion($turnoMasCercano, $esViernes);
    return $horario['Salida'];
}

/** @param array{Entrada: string, Salida: string} $turno */
function asistencia_obtener_horario_evaluacion(array $turno, bool $esViernes): array
{
    if (!$esViernes) {
        return ['Entrada' => $turno['Entrada'], 'Salida' => $turno['Salida']];
    }
    if ($turno['Entrada'] === '15:00:00' && $turno['Salida'] === '23:45:00') {
        return ['Entrada' => '14:00:00', 'Salida' => '22:00:00'];
    }
    return [
        'Entrada' => $turno['Entrada'],
        'Salida' => asistencia_restar_una_hora($turno['Salida']),
    ];
}

function asistencia_restar_una_hora(string $time): string
{
    $parts = explode(':', $time);
    $h = (int) ($parts[0] ?? 0);
    $m = (int) ($parts[1] ?? 0);
    $s = (int) ($parts[2] ?? 0);
    $dt = (new DateTimeImmutable('today'))->setTime($h, $m, $s)->modify('-1 hour');
    return $dt->format('H:i:s');
}

function asistencia_time_to_minutes(string $time): float
{
    $parts = explode(':', substr($time, 0, 8));
    return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
}

function asistencia_parse_fecha_baja(mixed $raw): ?DateTimeImmutable
{
    if ($raw === null || $raw === '') {
        return null;
    }
    if ($raw instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($raw)->setTime(0, 0);
    }
    $s = (string) $raw;
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', substr($s, 0, 10))
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $s);
    return $dt !== false ? $dt->setTime(0, 0) : null;
}
