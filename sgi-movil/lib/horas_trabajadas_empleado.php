<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/** Límite horario extra semana (18:00) y sábado (13:00). */
const HT_LIMITE_EXTRA_SEMANA = '18:00:00';
const HT_LIMITE_EXTRA_SABADO = '13:00:00';
const HT_FIN_EXTRA_LUNES = '04:00:00';
const HT_TURNO_ESPECIAL_ENTRADA = '15:00:00';
const HT_TURNO_ESPECIAL_SALIDA = '23:45:00';
const HT_TURNO_LOGISTICA_ENTRADA = '14:00:00';
const HT_TURNO_LOGISTICA_SALIDA = '23:00:00';
const HT_TOLERANCIA_FICHADA_FALTANTE = 60.0;

/**
 * Resumen de horas trabajadas de un empleado (HorasTrabajadasController.ProcesarEmpleadoHorasTrabajadas).
 *
 * @param array<string, mixed> $personal Id, Legajo, Nombre, Apellido, Estado, FechaBaja, AreaNombre; opcional TipoContratacionNombre, HorasExtras
 * @return array<string, mixed> Campos formateados para JSON móvil (HorasTrabajadasDetalleViewModel)
 */
function horas_trabajadas_build_resumen(PDO $pdo, array $personal, string $desde, string $hasta): array
{
    $desdeDt = DateTimeImmutable::createFromFormat('Y-m-d', $desde);
    $hastaDt = DateTimeImmutable::createFromFormat('Y-m-d', $hasta);
    if ($desdeDt === false || $hastaDt === false || $desdeDt > $hastaDt) {
        return horas_trabajadas_resumen_vacio($personal);
    }

    $empleado = horas_trabajadas_enriquecer_personal($pdo, $personal);
    $periodo = horas_trabajadas_normalizar_periodo($desdeDt, $hastaDt);
    $datos = horas_trabajadas_cargar_datos($pdo, $empleado, $periodo);

    return horas_trabajadas_procesar_empleado($periodo, $datos, $empleado);
}

/** @param array<string, mixed> $personal */
function horas_trabajadas_enriquecer_personal(PDO $pdo, array $personal): array
{
    $id = (string) ($personal['Id'] ?? '');
    if ($id === '') {
        return $personal;
    }
    if (isset($personal['TipoContratacionNombre'], $personal['HorasExtras'])) {
        return $personal;
    }

    $stmt = $pdo->prepare(
        'SELECT p.Id, p.Legajo, p.Nombre, p.Apellido, p.Foto, p.Estado, p.FechaBaja,
                p.HorasExtras, p.TipoContratacionId, tc.NombreTipoContratacion,
                a.Nombre AS AreaNombre
         FROM Personal p
         LEFT JOIN TipoContratacion tc ON tc.Id = p.TipoContratacionId
         LEFT JOIN Area a ON a.Id = p.ArealId
         WHERE p.Id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return $personal;
    }

    return array_merge($personal, [
        'Legajo' => (int) ($row['Legajo'] ?? $personal['Legajo'] ?? 0),
        'Nombre' => (string) ($row['Nombre'] ?? $personal['Nombre'] ?? ''),
        'Apellido' => (string) ($row['Apellido'] ?? $personal['Apellido'] ?? ''),
        'Foto' => (string) ($row['Foto'] ?? ''),
        'Estado' => (int) ($row['Estado'] ?? $personal['Estado'] ?? 1),
        'FechaBaja' => $row['FechaBaja'] ?? $personal['FechaBaja'] ?? null,
        'HorasExtras' => (bool) ($row['HorasExtras'] ?? false),
        'TipoContratacionId' => (int) ($row['TipoContratacionId'] ?? 0),
        'TipoContratacionNombre' => (string) ($row['NombreTipoContratacion'] ?? ''),
        'AreaNombre' => (string) ($row['AreaNombre'] ?? $personal['AreaNombre'] ?? $personal['Area'] ?? ''),
    ]);
}

/** @return array{fechaInicio: DateTimeImmutable, fechaFin: DateTimeImmutable, hoy: DateTimeImmutable, desde: DateTimeImmutable, hasta: DateTimeImmutable} */
function horas_trabajadas_normalizar_periodo(DateTimeImmutable $desde, DateTimeImmutable $hasta): array
{
    $fi = $desde->setTime(0, 0);
    $ff = $hasta->setTime(23, 59, 59);
    $hoy = new DateTimeImmutable('today');
    return [
        'fechaInicio' => $fi,
        'fechaFin' => $ff,
        'hoy' => $hoy,
        'desde' => $fi,
        'hasta' => $hasta,
    ];
}

/**
 * @param array<string, mixed> $empleado
 * @return array<string, mixed>
 */
function horas_trabajadas_cargar_datos(PDO $pdo, array $empleado, array $periodo): array
{
    $personalId = (string) ($empleado['Id'] ?? '');
    $legajo = (int) ($empleado['Legajo'] ?? 0);
    $desde = $periodo['desde']->format('Y-m-d');
    $hasta = $periodo['hasta']->format('Y-m-d');
    $fi = $periodo['fechaInicio']->format('Y-m-d H:i:s');
    $ff = $periodo['fechaFin']->format('Y-m-d H:i:s');

    $fichadas = [];
    if ($legajo > 0) {
        $claves = normalizar_legajo_claves($legajo);
        $placeholders = implode(',', array_fill(0, count($claves), '?'));
        $sql = "SELECT Fecha FROM FichadasGlobal
                WHERE Estado = 1 AND Fecha >= ? AND Fecha <= ? AND Legajo IN ($placeholders)
                ORDER BY Fecha ASC";
        $params = array_merge([$fi, $ff], $claves);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fichadas[] = ['Fecha' => new DateTimeImmutable((string) $row['Fecha'])];
        }
    }

    $turnosStmt = $pdo->query('SELECT Entrada, Salida FROM Turnos WHERE Estado = 1');
    $turnos = [];
    foreach ($turnosStmt->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $turnos[] = [
            'Entrada' => substr((string) $t['Entrada'], 0, 8),
            'Salida' => substr((string) $t['Salida'], 0, 8),
        ];
    }

    $feriados = [];
    $stmt = $pdo->prepare(
        'SELECT Fecha FROM PersonalFeriados WHERE Estado = 1 AND Fecha >= :d AND Fecha <= :h'
    );
    $stmt->execute(['d' => $desde, 'h' => $hasta]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $f) {
        $feriados[(string) $f] = true;
    }

    $authPorPersonal = [];
    $authSalidaLookup = [];
    if ($personalId !== '') {
        $stmt = $pdo->prepare(
            'SELECT Fecha, HoraSalida, HoraRegreso, FechaHoraSalidaEfectiva, FechaHoraRegresoEfectiva
             FROM AuthSalida
             WHERE Estado = 1 AND PersonalId = :pid AND Fecha >= :d AND Fecha <= :h'
        );
        $stmt->execute(['pid' => $personalId, 'd' => $desde, 'h' => $hasta]);
        $auths = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $authPorPersonal[$personalId] = $auths;
        foreach ($auths as $a) {
            $authSalidaLookup[$personalId . '|' . (string) $a['Fecha']] = true;
        }
    }

    $licenciasLookup = horas_trabajadas_licencias_rangos($pdo, $personalId, $desde, $hasta, null);
    $licenciasCgs = horas_trabajadas_licencias_rangos($pdo, $personalId, $desde, $hasta, 'cgs');
    $vacaciones = horas_trabajadas_licencias_rangos($pdo, $personalId, $desde, $hasta, 'vac');
    $ausenciasResta = horas_trabajadas_licencias_rangos($pdo, $personalId, $desde, $hasta, 'sgs');

    $extrasManuales = [];
    if ($personalId !== '') {
        $stmt = $pdo->prepare(
            'SELECT Fecha, Desde, Hasta FROM HoraExtraManual
             WHERE Estado = 1 AND PersonalId = :pid AND Fecha >= :d AND Fecha <= :h'
        );
        $stmt->execute(['pid' => $personalId, 'd' => $desde, 'h' => $hasta]);
        $extrasManuales[$personalId] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return [
        'fichadas' => $fichadas,
        'turnos' => $turnos,
        'feriados' => $feriados,
        'authPorPersonal' => $authPorPersonal,
        'authSalidaLookup' => $authSalidaLookup,
        'licenciasLookup' => $licenciasLookup,
        'licenciasCgs' => $licenciasCgs,
        'vacaciones' => $vacaciones,
        'ausenciasResta' => $ausenciasResta,
        'extrasManuales' => $extrasManuales,
    ];
}

/** @return list<array{Desde: string, Hasta: string, DesdeDate: DateTimeImmutable, HastaDate: DateTimeImmutable}> */
function horas_trabajadas_licencias_rangos(
    PDO $pdo,
    string $personalId,
    string $desde,
    string $hasta,
    ?string $tipo
): array {
    if ($personalId === '') {
        return [];
    }

    $sql = 'SELECT pl.Desde, pl.Hasta, m.Codigo, m.Resta
            FROM PersonalLicencias pl
            INNER JOIN PersonalLicenciasMotivo m ON m.Id = pl.PersonalLicenciasMotivoId
            WHERE pl.Estado = 1 AND pl.PersonalId = :pid
              AND pl.Desde <= :hasta AND pl.Hasta >= :desde';
    if ($tipo === 'cgs') {
        $sql .= " AND m.Resta = 0 AND m.Codigo <> 'VAC'";
    } elseif ($tipo === 'vac') {
        $sql .= " AND m.Codigo = 'VAC'";
    } elseif ($tipo === 'sgs') {
        $sql .= " AND m.Resta = 1 AND m.Codigo <> 'VAC'";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['pid' => $personalId, 'desde' => $desde, 'hasta' => $hasta]);
    $desdeDt = new DateTimeImmutable($desde);
    $hastaDt = new DateTimeImmutable($hasta);
    $lista = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $d = new DateTimeImmutable((string) $row['Desde']);
        $h = new DateTimeImmutable((string) $row['Hasta']);
        $clipDesde = $d < $desdeDt ? $desdeDt : $d;
        $clipHasta = $h > $hastaDt ? $hastaDt : $h;

        if ($tipo === 'vac') {
            $dias = (int) $clipHasta->diff($clipDesde)->days + 1;
            if ($dias <= 0) {
                continue;
            }
        } elseif ($tipo !== null) {
            if (ht_contar_dias_habiles($clipDesde, $clipHasta) <= 0) {
                continue;
            }
        }

        $lista[] = [
            'Desde' => $clipDesde->format('Y-m-d'),
            'Hasta' => $clipHasta->format('Y-m-d'),
            'DesdeDate' => $clipDesde,
            'HastaDate' => $clipHasta,
        ];
    }

    return $lista;
}

/**
 * @param array<string, mixed> $empleado
 * @param array<string, mixed> $datos
 * @return array<string, mixed>
 */
function horas_trabajadas_procesar_empleado(array $periodo, array $datos, array $empleado): array
{
    $personalId = (string) ($empleado['Id'] ?? '');
    $tipoNombre = (string) ($empleado['TipoContratacionNombre'] ?? '');
    $esMensualQuincenal = ht_es_mensual_o_quincenal($tipoNombre);
    $esQuincenal = ht_es_quincenal($tipoNombre);
    $areaNombre = (string) ($empleado['AreaNombre'] ?? $empleado['Area'] ?? '');
    $horasExtrasFlag = (bool) ($empleado['HorasExtras'] ?? false);

    $minutosAuth = horas_trabajadas_calcular_minutos_auth($empleado, $datos);

    $diasTrabajo = [];
    ht_agregar_feriados_habiles_periodo($diasTrabajo, $datos['feriados'], $periodo['desde'], $periodo['hasta']);
    ht_quitar_feriados_habiles_cubiertos_por_licencia(
        $diasTrabajo,
        $datos['feriados'],
        $personalId,
        $datos['licenciasCgs'],
        $datos['vacaciones'],
        $datos['ausenciasResta']
    );

    $licenciaCgs = ht_calcular_licencia(
        $personalId,
        $datos['licenciasCgs'],
        $periodo['desde'],
        $periodo['hasta'],
        $datos['feriados'],
        $esQuincenal
    );
    $diasVacaciones = ht_calcular_dias_vacaciones(
        $personalId,
        $datos['vacaciones'],
        $periodo['desde'],
        $periodo['hasta']
    );
    $licenciaSgs = ht_calcular_licencia(
        $personalId,
        $datos['ausenciasResta'],
        $periodo['desde'],
        $periodo['hasta'],
        $datos['feriados'],
        $esQuincenal
    );

    $fichadasEmp = $datos['fichadas'];

    if ($fichadasEmp === []) {
        $minutosTrab = ht_sumar_minutos_nominales(
            $diasTrabajo,
            $datos['feriados'],
            $periodo['desde'],
            $periodo['hasta'],
            $esMensualQuincenal,
            $personalId,
            $datos['licenciasCgs'],
            $datos['vacaciones'],
            $datos['ausenciasResta']
        );
        return horas_trabajadas_armar_respuesta(
            $empleado,
            $minutosTrab,
            count($diasTrabajo),
            (int) $minutosAuth,
            0,
            0,
            0,
            $licenciaCgs['dias'],
            $licenciaCgs['minutos'],
            $diasVacaciones,
            $licenciaSgs['dias'],
            false
        );
    }

    if (ht_tiene_fichada_incompleta(
        $fichadasEmp,
        $datos['turnos'],
        $datos['feriados'],
        $periodo['hoy'],
        $personalId,
        $areaNombre,
        $datos['authSalidaLookup'],
        $datos['licenciasLookup']
    )) {
        return horas_trabajadas_armar_respuesta($empleado, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, true);
    }

    [$e50, $e100, $comp] = horas_trabajadas_calcular_extras(
        $periodo,
        $datos,
        $empleado,
        $fichadasEmp,
        $diasTrabajo,
        $minutosAuth,
        $horasExtrasFlag,
        $areaNombre
    );

    $minutosTrab = ht_sumar_minutos_nominales(
        $diasTrabajo,
        $datos['feriados'],
        $periodo['desde'],
        $periodo['hasta'],
        $esMensualQuincenal,
        $personalId,
        $datos['licenciasCgs'],
        $datos['vacaciones'],
        $datos['ausenciasResta']
    );

    return horas_trabajadas_armar_respuesta(
        $empleado,
        $minutosTrab,
        count($diasTrabajo),
        (int) $minutosAuth,
        (int) $comp,
        (int) $e50,
        (int) $e100,
        $licenciaCgs['dias'],
        $licenciaCgs['minutos'],
        $diasVacaciones,
        $licenciaSgs['dias'],
        false
    );
}

/**
 * @param array<string, mixed> $empleado
 * @return array<string, mixed>
 */
function horas_trabajadas_armar_respuesta(
    array $empleado,
    int $minutosHorasTrab,
    int $diasTrabajados,
    int $minutosAuthSalida,
    int $minutosAuthCompensada,
    int $minutosExtra50,
    int $minutosExtra100,
    int $diasLicencia,
    int $minutosLicenciaConGoce,
    int $diasVacacionesConsumidas,
    int $diasAusenciaResta,
    bool $fichadaIncompleta
): array {
    $minutosAuthSalida = ht_redondear_auth_minutos($minutosAuthSalida);
    $minutosAuthCompensada = ht_redondear_auth_minutos($minutosAuthCompensada);
    $minutosAuthPendiente = max(0, $minutosAuthSalida - $minutosAuthCompensada);
    $minutosTotalAPagar = $minutosHorasTrab + $minutosLicenciaConGoce - $minutosAuthPendiente;

    $horasTrab = intdiv($minutosHorasTrab, 60);
    $restoTrab = $minutosHorasTrab % 60;
    $horasTotal = intdiv($minutosTotalAPagar, 60);
    $restoTotal = $minutosTotalAPagar % 60;

    return [
        'legajo' => (int) ($empleado['Legajo'] ?? 0),
        'nombre' => (string) ($empleado['Nombre'] ?? ''),
        'apellido' => (string) ($empleado['Apellido'] ?? ''),
        'estado' => (int) ($empleado['Estado'] ?? 1),
        'fechaBaja' => ht_format_fecha_baja($empleado['FechaBaja'] ?? null),
        'horasTrabajadasFormateadas' => sprintf('%02d:%02d', $horasTrab, $restoTrab),
        'diasTrabajados' => $diasTrabajados,
        'authSalidaFormateada' => intdiv($minutosAuthSalida, 60) . ' hs.',
        'authSalidaCompensadaFormateada' => intdiv($minutosAuthCompensada, 60) . ' hs.',
        'diasLicencia' => $diasLicencia,
        'diasAusenciaResta' => $diasAusenciaResta,
        'diasVacacionesConsumidas' => $diasVacacionesConsumidas,
        'totalAPagarFormateado' => sprintf('%02d:%02d', $horasTotal, $restoTotal),
        'horasExtra50Formateadas' => intdiv($minutosExtra50, 60) . ' hs.',
        'horasExtra100Formateadas' => intdiv($minutosExtra100, 60) . ' hs.',
        'tieneFichadaIncompleta' => $fichadaIncompleta,
        'minutosTrabajados' => $minutosHorasTrab,
        'minutosAuthSalida' => $minutosAuthSalida,
        'minutosAuthSalidaCompensada' => $minutosAuthCompensada,
        'minutosExtra50' => $minutosExtra50,
        'minutosExtra100' => $minutosExtra100,
        'minutosTotalAPagar' => $minutosTotalAPagar,
        'minutosLicenciaConGoce' => $minutosLicenciaConGoce,
    ];
}

/** @param array<string, mixed> $empleado */
function horas_trabajadas_resumen_vacio(array $empleado): array
{
    return horas_trabajadas_armar_respuesta($empleado, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, false);
}

/** @param array<string, mixed> $empleado */
function horas_trabajadas_calcular_minutos_auth(array $empleado, array $datos): float
{
    $personalId = (string) ($empleado['Id'] ?? '');
    $areaNombre = (string) ($empleado['AreaNombre'] ?? $empleado['Area'] ?? '');
    if ($personalId === '' || !isset($datos['authPorPersonal'][$personalId])) {
        return 0.0;
    }

    $minutosAuth = 0.0;
    foreach ($datos['authPorPersonal'][$personalId] as $a) {
        if (!empty($a['FechaHoraSalidaEfectiva'])) {
            $salida = new DateTimeImmutable((string) $a['FechaHoraSalidaEfectiva']);
            $salidaTs = (int) $salida->format('H') * 3600 + (int) $salida->format('i') * 60;
        } else {
            $salidaTs = ht_time_to_seconds(substr((string) $a['HoraSalida'], 0, 8));
        }

        if (!empty($a['FechaHoraRegresoEfectiva'])) {
            $regreso = new DateTimeImmutable((string) $a['FechaHoraRegresoEfectiva']);
            $regresoTs = (int) $regreso->format('H') * 3600 + (int) $regreso->format('i') * 60;
        } else {
            $fecha = new DateTimeImmutable((string) $a['Fecha']);
            $regresoTs = ht_obtener_regreso_por_fin_de_turno(
                $datos['turnos'],
                $fecha,
                $salidaTs,
                ht_time_to_seconds(substr((string) $a['HoraRegreso'], 0, 8)),
                $areaNombre
            );
        }

        $minutosAuth += max(0.0, ($regresoTs - $salidaTs) / 60.0);
    }

    return $minutosAuth;
}

/**
 * @param list<array{Fecha: DateTimeImmutable}> $fichadasEmp
 * @param array<string, bool> $diasTrabajo keys Y-m-d
 */
function horas_trabajadas_calcular_extras(
    array $periodo,
    array $datos,
    array $empleado,
    array $fichadasEmp,
    array &$diasTrabajo,
    float $minutosAuth,
    bool $horasExtrasFlag,
    string $areaNombre
): array {
    $personalId = (string) ($empleado['Id'] ?? '');
    $e50 = 0.0;
    $e100 = 0.0;
    ht_acumular_extras_manuales($personalId, $datos['extrasManuales'], $e50, $e100);

    $porDia = [];
    foreach ($fichadasEmp as $f) {
        $key = $f['Fecha']->format('Y-m-d');
        $porDia[$key][] = $f;
    }

    foreach ($porDia as $fechaKey => $grupo) {
        usort($grupo, static fn ($a, $b) => $a['Fecha'] <=> $b['Fecha']);
        $entrada = $grupo[0]['Fecha'];
        $salida = $grupo[count($grupo) - 1]['Fecha'];
        ht_procesar_grupo_dia_fichadas(
            $fechaKey,
            $entrada,
            $salida,
            count($grupo),
            $periodo,
            $datos,
            $personalId,
            $horasExtrasFlag,
            $areaNombre,
            $diasTrabajo,
            $e50,
            $e100
        );
    }

    $e50 = (float) ht_redondear_hora_extra_completa($e50);
    $e100 = (float) ht_redondear_hora_extra_completa($e100);

    $deuda = $minutosAuth;
    $comp = min($e50, $deuda);
    $e50 -= $comp;
    $deuda -= $comp;
    if ($deuda > 0) {
        $c2 = min($e100, $deuda);
        $e100 -= $c2;
        $comp += $c2;
    }

    return [$e50, $e100, $comp];
}

function ht_procesar_grupo_dia_fichadas(
    string $fechaKey,
    DateTimeImmutable $entrada,
    DateTimeImmutable $salida,
    int $cantidadFichadas,
    array $periodo,
    array $datos,
    string $personalId,
    bool $horasExtrasFlag,
    string $areaNombre,
    array &$diasTrabajo,
    float &$e50,
    float &$e100
): void {
    if (isset($datos['feriados'][$fechaKey])) {
        return;
    }

    $dia = $entrada;
    $hoyStr = $periodo['hoy']->format('Y-m-d');
    $diffMin = ($salida->getTimestamp() - $entrada->getTimestamp()) / 60.0;

    if ($diffMin < 30 && $fechaKey !== $hoyStr) {
        return;
    }

    $dow = (int) $dia->format('w');
    if ($dow === 0 || $dow === 6) {
        if ($horasExtrasFlag) {
            ht_acumular_extras_segun_franja($entrada, $salida, $e50, $e100);
        }
        return;
    }

    if ($dow === 1 && $entrada->format('H:i:s') < HT_FIN_EXTRA_LUNES) {
        $corte = $dia->setTime(4, 0);
        $finTramo = $salida < $corte ? $salida : $corte;
        if ($horasExtrasFlag) {
            ht_acumular_extras_segun_franja($entrada, $finTramo, $e50, $e100);
        }
        if ($salida <= $corte) {
            return;
        }
        $entrada = $corte;
    }

    $turno = ht_obtener_turno_asignado($datos['turnos'], $entrada, $salida, $dia, $areaNombre);
    if ($turno['turno'] === null) {
        return;
    }

    $iniAsig = $turno['inicio'];
    $finAsig = $turno['fin'];
    $inicioNormal = $entrada > $iniAsig ? $entrada : $iniAsig;
    $finNormal = $salida < $finAsig ? $salida : $finAsig;

    if ($finNormal <= $inicioNormal) {
        return;
    }

    $difEntrada = ($entrada->getTimestamp() - $iniAsig->getTimestamp()) / 60.0;
    if ($difEntrada <= 9) {
        $inicioNormal = $iniAsig;
    } else {
        $inicioTurnoComputable = $iniAsig->modify('+1 hour');
        if ($inicioNormal < $inicioTurnoComputable) {
            $inicioNormal = $inicioTurnoComputable;
        }
    }

    $tiempoSeg = $finNormal->getTimestamp() - $inicioNormal->getTimestamp();
    $turnoRow = $turno['turno'];
    if ($turnoRow['Entrada'] === HT_TURNO_ESPECIAL_ENTRADA && $turnoRow['Salida'] === HT_TURNO_ESPECIAL_SALIDA) {
        $tiempoSeg += 15 * 60;
    }
    $minutosNormalesHoy = $tiempoSeg / 60.0;

    if ($dow === 5 && $minutosNormalesHoy > 480) {
        $minutosExtra = $minutosNormalesHoy - 480;
        $minutosNormalesHoy = 480.0;
        if ($minutosExtra >= 60 && $horasExtrasFlag) {
            $e50 += (int) ($minutosExtra / 60) * 60;
        }
    }

    $diaExcluido = ht_dia_excluido_por_licencia_formal(
        $personalId,
        $fechaKey,
        $datos['licenciasCgs'],
        $datos['vacaciones'],
        $datos['ausenciasResta']
    );

    $umbral = $dow === 5 ? 480.0 : 540.0;
    if (!$diaExcluido && $minutosNormalesHoy >= $umbral) {
        $diasTrabajo[$fechaKey] = true;
    }

    if ($horasExtrasFlag) {
        $limite = $dow === 6 ? HT_LIMITE_EXTRA_SABADO : HT_LIMITE_EXTRA_SEMANA;
        if ($entrada < $iniAsig) {
            ht_acumular_extra_por_horario($entrada, $iniAsig, $limite, $e50, $e100);
        }
        if ($salida > $finAsig) {
            ht_acumular_extra_por_horario($finAsig, $salida, $limite, $e50, $e100);
        }
    }
}

function ht_tiene_fichada_incompleta(
    array $fichadasEmp,
    array $turnos,
    array $feriados,
    DateTimeImmutable $hoy,
    string $personalId,
    string $areaNombre,
    array $authSalidaLookup,
    array $licenciasLookup
): bool {
    if ($fichadasEmp === []) {
        return false;
    }

    $hoyStr = $hoy->format('Y-m-d');
    $porDia = [];
    foreach ($fichadasEmp as $f) {
        $key = $f['Fecha']->format('Y-m-d');
        $porDia[$key][] = $f;
    }

    foreach ($porDia as $fechaKey => $dia) {
        if (isset($feriados[$fechaKey])) {
            continue;
        }
        if ($fechaKey === $hoyStr) {
            continue;
        }

        foreach ($licenciasLookup as $lic) {
            if ($fechaKey >= $lic['Desde'] && $fechaKey <= $lic['Hasta']) {
                continue 2;
            }
        }

        if (isset($authSalidaLookup[$personalId . '|' . $fechaKey])) {
            continue;
        }

        usort($dia, static fn ($a, $b) => $a['Fecha'] <=> $b['Fecha']);
        $entradaDia = $dia[0]['Fecha'];
        $salidaDia = $dia[count($dia) - 1]['Fecha'];
        $diff = ($salidaDia->getTimestamp() - $entradaDia->getTimestamp()) / 60.0;

        if (count($dia) === 1 || $diff < 30) {
            return true;
        }

        $dow = (int) $entradaDia->format('w');
        if ($dow === 0 || $dow === 6) {
            continue;
        }

        $turno = ht_obtener_turno_asignado($turnos, $entradaDia, $salidaDia, $entradaDia, $areaNombre);
        if ($turno['turno'] === null) {
            continue;
        }

        if (($entradaDia->getTimestamp() - $turno['inicio']->getTimestamp()) / 60.0 >= HT_TOLERANCIA_FICHADA_FALTANTE) {
            return true;
        }
        if (($turno['fin']->getTimestamp() - $salidaDia->getTimestamp()) / 60.0 >= HT_TOLERANCIA_FICHADA_FALTANTE) {
            return true;
        }
    }

    return false;
}

/** @return array{dias: int, minutos: int} */
function ht_calcular_licencia(
    string $personalId,
    array $licencias,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta,
    array $feriados,
    bool $incluirFeriadosFinDeSemana
): array {
    $diasCubiertos = [];
    foreach ($licencias as $lic) {
        for ($cur = $lic['DesdeDate']; $cur <= $lic['HastaDate']; $cur = $cur->modify('+1 day')) {
            $dow = (int) $cur->format('w');
            $esFinDeSemana = $dow === 0 || $dow === 6;
            $fechaStr = $cur->format('Y-m-d');
            if (!$esFinDeSemana || ($incluirFeriadosFinDeSemana && isset($feriados[$fechaStr]))) {
                $diasCubiertos[$fechaStr] = true;
            }
        }
    }

    $minutos = 0;
    foreach (array_keys($diasCubiertos) as $fechaStr) {
        $d = new DateTimeImmutable($fechaStr);
        $dow = (int) $d->format('w');
        if ($dow === 0 || $dow === 6) {
            $minutos += 9 * 60;
        } else {
            $minutos += ht_minutos_horas_trabajadas_dia_laboral($d, $feriados);
        }
    }

    return ['dias' => count($diasCubiertos), 'minutos' => $minutos];
}

function ht_calcular_dias_vacaciones(
    string $personalId,
    array $vacaciones,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta
): int {
    $total = 0;
    foreach ($vacaciones as $lic) {
        $total += ht_contar_dias_habiles($lic['DesdeDate'], $lic['HastaDate']);
    }
    return $total;
}

function ht_sumar_minutos_nominales(
    array $diasTrabajo,
    array $feriados,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta,
    bool $esMensualQuincenal,
    string $personalId,
    array $licenciasCgs,
    array $vacaciones,
    array $ausenciasResta
): int {
    $m = 0;
    foreach (array_keys($diasTrabajo) as $fechaStr) {
        $d = new DateTimeImmutable($fechaStr);
        $m += ht_minutos_horas_trabajadas_dia_laboral($d, $feriados);
    }
    $m += ht_minutos_feriados_fin_de_semana_mq(
        $feriados,
        $desde,
        $hasta,
        $esMensualQuincenal,
        $personalId,
        $licenciasCgs,
        $vacaciones,
        $ausenciasResta
    );
    return $m;
}

function ht_contar_dias_habiles(DateTimeImmutable $desde, DateTimeImmutable $hasta): int
{
    $dias = 0;
    for ($cur = $desde; $cur <= $hasta; $cur = $cur->modify('+1 day')) {
        $dow = (int) $cur->format('w');
        if ($dow !== 0 && $dow !== 6) {
            $dias++;
        }
    }
    return $dias;
}

function ht_minutos_horas_trabajadas_dia_laboral(DateTimeImmutable $fecha, array $feriados): int
{
    $dow = (int) $fecha->format('w');
    if ($dow === 0 || $dow === 6) {
        return 0;
    }
    $fechaStr = $fecha->format('Y-m-d');
    if (isset($feriados[$fechaStr])) {
        return 9 * 60;
    }
    return $dow === 5 ? 8 * 60 : 9 * 60;
}

function ht_agregar_feriados_habiles_periodo(
    array &$destino,
    array $feriados,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta
): void {
    foreach (array_keys($feriados) as $fechaStr) {
        $f = new DateTimeImmutable($fechaStr);
        if ($f < $desde || $f > $hasta) {
            continue;
        }
        $dow = (int) $f->format('w');
        if ($dow !== 0 && $dow !== 6) {
            $destino[$fechaStr] = true;
        }
    }
}

function ht_quitar_feriados_habiles_cubiertos_por_licencia(
    array &$diasTrabajo,
    array $feriados,
    string $personalId,
    array $licenciasCgs,
    array $vacaciones,
    array $ausenciasResta
): void {
    foreach (array_keys($diasTrabajo) as $fechaStr) {
        if (!isset($feriados[$fechaStr])) {
            continue;
        }
        if (ht_dia_excluido_por_licencia_formal($personalId, $fechaStr, $licenciasCgs, $vacaciones, $ausenciasResta)) {
            unset($diasTrabajo[$fechaStr]);
        }
    }
}

function ht_dia_excluido_por_licencia_formal(
    string $personalId,
    string $fecha,
    array $licenciasCgs,
    array $vacaciones,
    array $ausenciasResta
): bool {
    foreach ([$licenciasCgs, $vacaciones, $ausenciasResta] as $lista) {
        foreach ($lista as $lic) {
            if ($fecha >= $lic['Desde'] && $fecha <= $lic['Hasta']) {
                return true;
            }
        }
    }
    return false;
}

function ht_minutos_feriados_fin_de_semana_mq(
    array $feriados,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta,
    bool $aplicar,
    string $personalId,
    array $licenciasCgs,
    array $vacaciones,
    array $ausenciasResta
): int {
    if (!$aplicar) {
        return 0;
    }
    $m = 0;
    foreach (array_keys($feriados) as $fechaStr) {
        $f = new DateTimeImmutable($fechaStr);
        if ($f < $desde || $f > $hasta) {
            continue;
        }
        $dow = (int) $f->format('w');
        if ($dow !== 0 && $dow !== 6) {
            continue;
        }
        if (ht_dia_excluido_por_licencia_formal($personalId, $fechaStr, $licenciasCgs, $vacaciones, $ausenciasResta)) {
            continue;
        }
        $m += 9 * 60;
    }
    return $m;
}

function ht_acumular_extras_manuales(string $personalId, array $extrasPorPersonal, float &$e50, float &$e100): void
{
    if ($personalId === '' || !isset($extrasPorPersonal[$personalId])) {
        return;
    }
    foreach ($extrasPorPersonal[$personalId] as $e) {
        $fecha = (string) $e['Fecha'];
        $desde = new DateTimeImmutable($fecha . ' ' . substr((string) $e['Desde'], 0, 8));
        $hasta = new DateTimeImmutable($fecha . ' ' . substr((string) $e['Hasta'], 0, 8));
        ht_acumular_extras_segun_franja($desde, $hasta, $e50, $e100);
    }
}

function ht_acumular_extras_segun_franja(
    DateTimeImmutable $entrada,
    DateTimeImmutable $salida,
    float &$e50,
    float &$e100
): void {
    if ($salida <= $entrada) {
        return;
    }

    $dow = (int) $entrada->format('w');
    if ($dow === 0) {
        $e100 += ht_redondear_hora_extra_completa(($salida->getTimestamp() - $entrada->getTimestamp()) / 60.0);
        return;
    }

    if ($dow === 1 && $entrada->format('H:i:s') < HT_FIN_EXTRA_LUNES) {
        $corte = $entrada->setTime(4, 0);
        $finTramo = $salida < $corte ? $salida : $corte;
        $e100 += ht_redondear_hora_extra_completa(($finTramo->getTimestamp() - $entrada->getTimestamp()) / 60.0);
        if ($salida <= $corte) {
            return;
        }
        $entrada = $corte;
        $dow = (int) $entrada->format('w');
    }

    $limite = $dow === 6 ? HT_LIMITE_EXTRA_SABADO : HT_LIMITE_EXTRA_SEMANA;
    ht_acumular_extra_por_horario($entrada, $salida, $limite, $e50, $e100);
}

function ht_acumular_extra_por_horario(
    DateTimeImmutable $inicioExtra,
    DateTimeImmutable $finExtra,
    string $limiteHorario,
    float &$extra50,
    float &$extra100
): void {
    if ($finExtra <= $inicioExtra) {
        return;
    }

    $parts = explode(':', $limiteHorario);
    $corte = $inicioExtra->setTime((int) $parts[0], (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0));

    if ($finExtra <= $corte) {
        $extra50 += ht_redondear_hora_extra_completa(ht_minutos_sin_segundos($inicioExtra, $finExtra));
        return;
    }
    if ($inicioExtra >= $corte) {
        $extra100 += ht_redondear_hora_extra_completa(ht_minutos_sin_segundos($inicioExtra, $finExtra));
        return;
    }

    $extra50 += ht_redondear_hora_extra_completa(ht_minutos_sin_segundos($inicioExtra, $corte));
    $extra100 += ht_redondear_hora_extra_completa(ht_minutos_sin_segundos($corte, $finExtra));
}

function ht_minutos_sin_segundos(DateTimeImmutable $inicio, DateTimeImmutable $fin): float
{
    $i = $inicio->setTime((int) $inicio->format('H'), (int) $inicio->format('i'), 0);
    $f = $fin->setTime((int) $fin->format('H'), (int) $fin->format('i'), 0);
    if ($f <= $i) {
        return 0.0;
    }
    return ($f->getTimestamp() - $i->getTimestamp()) / 60.0;
}

function ht_redondear_hora_extra_completa(float $minutos): int
{
    if ($minutos <= 0) {
        return 0;
    }
    return (int) ($minutos / 60) * 60;
}

function ht_redondear_auth_minutos(int $minutos): int
{
    if ($minutos === 0) {
        return 0;
    }
    return (int) ceil($minutos / 60.0) * 60;
}

function ht_obtener_turno_asignado(
    array $turnos,
    DateTimeImmutable $entrada,
    DateTimeImmutable $salida,
    DateTimeImmutable $dia,
    string $areaNombre
): array {
    $turnoAsignado = null;
    $menorDif = PHP_FLOAT_MAX;
    $iniAsig = null;
    $finAsig = null;
    $esLogistica = ht_es_area_logistica($areaNombre);
    $esViernes = (int) $dia->format('w') === 5;

    foreach ($turnos as $t) {
        if (!$esLogistica && ht_es_turno_logistica($t)) {
            continue;
        }
        $horario = ht_obtener_horario_evaluacion_turno($t, $esViernes);
        $ini = $dia->setTime(
            (int) substr($horario['Entrada'], 0, 2),
            (int) substr($horario['Entrada'], 3, 2),
            (int) substr($horario['Entrada'], 6, 2)
        );
        $fin = $dia->setTime(
            (int) substr($horario['Salida'], 0, 2),
            (int) substr($horario['Salida'], 3, 2),
            (int) substr($horario['Salida'], 6, 2)
        );
        if ($fin <= $ini) {
            $fin = $fin->modify('+1 day');
        }

        $dif = abs(($entrada->getTimestamp() - $ini->getTimestamp()) / 60)
            + abs(($salida->getTimestamp() - $fin->getTimestamp()) / 60);

        if ($dif < $menorDif) {
            $menorDif = $dif;
            $turnoAsignado = $t;
            $iniAsig = $ini;
            $finAsig = $fin;
        }
    }

    return ['turno' => $turnoAsignado, 'inicio' => $iniAsig, 'fin' => $finAsig];
}

function ht_obtener_regreso_por_fin_de_turno(
    array $turnos,
    DateTimeImmutable $fecha,
    int $salidaAutorizadaSec,
    int $regresoInformadoSec,
    string $areaNombre
): int
{
    $dia = $fecha->setTime(0, 0);
    $entradaReferencia = $dia->getTimestamp() + $salidaAutorizadaSec;
    $salidaReferencia = $dia->getTimestamp() + $regresoInformadoSec;
    if ($salidaReferencia <= $entradaReferencia) {
        $salidaReferencia += 86400;
    }

    $entradaDt = (new DateTimeImmutable())->setTimestamp($entradaReferencia);
    $salidaDt = (new DateTimeImmutable())->setTimestamp($salidaReferencia);
    $turno = ht_obtener_turno_asignado($turnos, $entradaDt, $salidaDt, $fecha, $areaNombre);
    if ($turno['fin'] === null) {
        return $regresoInformadoSec;
    }
    return (int) $turno['fin']->format('H') * 3600 + (int) $turno['fin']->format('i') * 60;
}

function ht_obtener_horario_evaluacion_turno(array $turno, bool $esViernes): array
{
    if (!$esViernes) {
        return ['Entrada' => $turno['Entrada'], 'Salida' => $turno['Salida']];
    }
    if ($turno['Entrada'] === HT_TURNO_ESPECIAL_ENTRADA && $turno['Salida'] === HT_TURNO_ESPECIAL_SALIDA) {
        return [
            'Entrada' => ht_restar_una_hora_time($turno['Entrada']),
            'Salida' => '22:00:00',
        ];
    }
    return ['Entrada' => $turno['Entrada'], 'Salida' => ht_restar_una_hora_time($turno['Salida'])];
}

function ht_restar_una_hora_time(string $time): string
{
    $h = (int) substr($time, 0, 2);
    $m = (int) substr($time, 3, 2);
    $s = (int) substr($time, 6, 2);
    return (new DateTimeImmutable('today'))->setTime($h, $m, $s)->modify('-1 hour')->format('H:i:s');
}

function ht_es_turno_logistica(array $turno): bool
{
    return $turno['Entrada'] === HT_TURNO_LOGISTICA_ENTRADA
        && $turno['Salida'] === HT_TURNO_LOGISTICA_SALIDA;
}

function ht_es_area_logistica(string $areaNombre): bool
{
    if ($areaNombre === '') {
        return false;
    }
    $normalizado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $areaNombre) ?: $areaNombre;
    return strtoupper(trim($normalizado)) === 'LOGISTICA';
}

function ht_es_mensual_o_quincenal(string $nombre): bool
{
    $t = trim($nombre);
    return strcasecmp($t, 'Mensual') === 0 || strcasecmp($t, 'Quincenal') === 0;
}

function ht_es_quincenal(string $nombre): bool
{
    return strcasecmp(trim($nombre), 'Quincenal') === 0;
}

function ht_time_to_seconds(string $time): int
{
    $parts = explode(':', substr($time, 0, 8));
    return ((int) ($parts[0] ?? 0)) * 3600 + ((int) ($parts[1] ?? 0)) * 60 + ((int) ($parts[2] ?? 0));
}

function ht_format_fecha_baja(mixed $raw): ?string
{
    if ($raw === null || $raw === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', substr((string) $raw, 0, 10))
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $raw);
    return $dt !== false ? $dt->format('d/m/Y') : null;
}
