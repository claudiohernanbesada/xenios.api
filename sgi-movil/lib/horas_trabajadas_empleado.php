<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/registro_personal_jornadas.php';

/** Límite horario extra semana (18:00) y sábado (13:00). */
const HT_LIMITE_EXTRA_SEMANA = '18:00:00';
const HT_LIMITE_EXTRA_SABADO = '13:00:00';
const HT_FIN_EXTRA_LUNES = '04:00:00';
const HT_TURNO_ESPECIAL_ENTRADA = '15:00:00';
const HT_TURNO_ESPECIAL_SALIDA = '23:45:00';
const HT_TURNO_LOGISTICA_ENTRADA = '14:00:00';
const HT_TURNO_LOGISTICA_SALIDA = '23:00:00';
const HT_TOLERANCIA_FICHADA_FALTANTE = 60.0;
const TOLERANCIA_MINUTOS_REGISTRO_PERSONAL = 9;
const HT_MAXIMO_HORAS_EXTRAS_DIA = 3;

/**
 * Resumen de horas trabajadas de un empleado (HorasTrabajadasController.ProcesarEmpleadoHorasTrabajadas).
 *
 * @param array<string, mixed> $personal Id, Legajo, Nombre, Apellido, Estado, FechaBaja, AreaNombre; opcional TipoContratacionNombre, HorasExtras
 * @return array<string, mixed> Campos formateados para JSON móvil (HorasTrabajadasDetalleViewModel)
 */
function horas_trabajadas_build_resumen(PDO $pdo, array $personal, string $desde, string $hasta, array $config = []): array
{
    $desdeDt = DateTimeImmutable::createFromFormat('Y-m-d', $desde);
    $hastaDt = DateTimeImmutable::createFromFormat('Y-m-d', $hasta);
    if ($desdeDt === false || $hastaDt === false || $desdeDt > $hastaDt) {
        return horas_trabajadas_resumen_vacio($personal);
    }

    $empleado = horas_trabajadas_enriquecer_personal($pdo, $personal);
    $periodo = horas_trabajadas_normalizar_periodo($desdeDt, $hastaDt);
    $datos = horas_trabajadas_cargar_datos($pdo, $empleado, $periodo);
    $datos['config'] = ht_asistencia_calculo_config($config);

    return horas_trabajadas_procesar_empleado($periodo, $datos, $empleado);
}

function ht_asistencia_calculo_config(array $config): array
{
    $calculo = $config['asistencia_calculo'] ?? [];
    return [
        'max_duracion_jornada_horas' => (int) ($calculo['max_duracion_jornada_horas'] ?? 13),
        'descanso_minimo_horas' => (int) ($calculo['descanso_minimo_horas'] ?? 8),
        'fraccionamiento_minutos' => max(1, (int) ($calculo['fraccionamiento_minutos'] ?? 60)),
        'deduplicacion_minutos' => max(0, (int) ($calculo['deduplicacion_minutos'] ?? 2)),
        'jornada_vigilancia_horas' => (int) ($calculo['jornada_vigilancia_horas'] ?? 12),
        'maximo_horas_extras_dia' => (int) ($calculo['maximo_horas_extras_dia'] ?? 3),
    ];
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
                p.HorarioIngreso, p.HorarioSalida,
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
        'HorarioIngreso' => $row['HorarioIngreso'] ?? $personal['HorarioIngreso'] ?? null,
        'HorarioSalida' => $row['HorarioSalida'] ?? $personal['HorarioSalida'] ?? null,
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
    $fi = $periodo['fechaInicio']->modify('-1 day')->format('Y-m-d H:i:s');
    $ingresoNorm = ht_parse_horario_empleado($empleado['HorarioIngreso'] ?? null);
    $salidaNorm = ht_parse_horario_empleado($empleado['HorarioSalida'] ?? null);
    // Consultar contexto para cerrar tambien turnos reales que difieren del horario habitual.
    $finFichadas = $periodo['fechaFin']->modify('+1 day');
    $ff = $finFichadas->format('Y-m-d H:i:s');

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
        $marcas = array_map(static fn(array $r): array => ['Fecha' => new DateTimeImmutable((string) $r['Fecha'])], $stmt->fetchAll(PDO::FETCH_ASSOC));
        foreach (registro_personal_agrupar_jornadas($marcas, $ingresoNorm, $salidaNorm) as $fechaLaboral => $grupo) {
            if ($fechaLaboral >= $desde && $fechaLaboral <= $hasta) {
                array_push($fichadas, ...$grupo);
            }
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
            'SELECT a.Fecha, a.HoraSalida, a.FechaRegreso, a.HoraRegreso, a.FechaHoraSalidaEfectiva, a.FechaHoraRegresoEfectiva,
                    COALESCE(m.RequiereRecupero, 1) AS RequiereRecupero
             FROM AuthSalida a
             LEFT JOIN AuthSalidaMotivos m ON m.Id = a.MotivoId
             WHERE a.Estado = 1 AND a.PersonalId = :pid AND a.Fecha <= :h AND a.FechaRegreso >= :d'
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
            'SELECT h.Fecha, h.Desde, h.Hasta, m.Motivo, m.Estado AS MotivoEstado
             FROM HoraExtraManual h LEFT JOIN HoraExtraManualMotivo m ON m.Id = h.MotivoId
             WHERE h.Estado = 1 AND h.PersonalId = :pid AND h.Fecha >= :d AND h.Fecha <= :h'
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

    $sql = 'SELECT pl.Desde, pl.Hasta, pl.Comentarios, m.Codigo, m.Resta
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
            'Comentarios' => $row['Comentarios'] ?? null,
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
    $esQuincenal = ht_es_quincenal($tipoNombre);
    $areaNombre = (string) ($empleado['AreaNombre'] ?? $empleado['Area'] ?? '');
    $horasExtrasFlag = (bool) ($empleado['HorasExtras'] ?? false);

    $minutosAuth = horas_trabajadas_calcular_minutos_auth($empleado, $datos);

    $diasTrabajo = [];
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

    if ($esQuincenal) {
        ht_agregar_feriados_habiles_no_trabajados_quincenal(
            $diasTrabajo,
            $datos['feriados'],
            $periodo['desde'],
            $periodo['hasta'],
            $personalId,
            $datos['licenciasCgs'],
            $datos['vacaciones'],
            $datos['ausenciasResta']
        );
    }
    $minutosFeriados = ht_sumar_minutos_feriados_no_trabajados(
        $diasTrabajo,
        $datos['feriados'],
        $areaNombre,
        (int) ($datos['config']['jornada_vigilancia_horas'] ?? 12)
    );

    if ($fichadasEmp === []) {
        $minutosTrab = ht_sumar_minutos_nominales(
            $diasTrabajo,
            $datos['feriados'],
            $periodo['desde'],
            $periodo['hasta'],
            $esQuincenal,
            $personalId,
            $datos['licenciasCgs'],
            $datos['vacaciones'],
            $datos['ausenciasResta'],
            $areaNombre,
            (int) ($datos['config']['jornada_vigilancia_horas'] ?? 12)
        );
        return horas_trabajadas_armar_respuesta(
            $empleado,
            $minutosTrab,
            count($diasTrabajo),
            (int) $minutosAuth,
            0,
            $esQuincenal
                ? ht_minutos_extras50_feriados_fin_de_semana_quincenal(
                    $datos['feriados'],
                    $periodo['desde'],
                    $periodo['hasta'],
                    $personalId,
                    [],
                    $datos['licenciasCgs'],
                    $datos['vacaciones'],
                    $datos['ausenciasResta']
                )
                : 0,
            0,
            $licenciaCgs['dias'],
            $licenciaCgs['minutos'],
            $minutosFeriados,
            $diasVacaciones,
            $licenciaSgs['dias'],
            false
        );
    }

    if (ht_tiene_fichada_incompleta(
        $fichadasEmp,
        $datos['turnos'],
        $datos['feriados'],
        $periodo['desde'],
        $periodo['hasta'],
        $periodo['hoy'],
        $personalId,
        $areaNombre,
        $empleado['HorarioIngreso'] ?? null,
        $empleado['HorarioSalida'] ?? null,
        $datos['authSalidaLookup'],
        $datos['licenciasLookup']
    )) {
        return horas_trabajadas_armar_respuesta($empleado, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, true);
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

    if ($esQuincenal) {
        ht_agregar_feriados_habiles_no_trabajados_quincenal(
            $diasTrabajo,
            $datos['feriados'],
            $periodo['desde'],
            $periodo['hasta'],
            $personalId,
            $datos['licenciasCgs'],
            $datos['vacaciones'],
            $datos['ausenciasResta']
        );
    }
    $minutosFeriados = ht_sumar_minutos_feriados_no_trabajados(
        $diasTrabajo,
        $datos['feriados'],
        $areaNombre,
        (int) ($datos['config']['jornada_vigilancia_horas'] ?? 12)
    );

    $minutosTrab = ht_sumar_minutos_nominales(
        $diasTrabajo,
        $datos['feriados'],
        $periodo['desde'],
        $periodo['hasta'],
        $esQuincenal,
        $personalId,
        $datos['licenciasCgs'],
        $datos['vacaciones'],
        $datos['ausenciasResta'],
        $areaNombre,
        (int) ($datos['config']['jornada_vigilancia_horas'] ?? 12)
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
        $minutosFeriados,
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
    int $minutosFeriados,
    int $diasVacacionesConsumidas,
    int $diasAusenciaResta,
    bool $fichadaIncompleta
): array {
    $minutosAuthSalida = ht_redondear_auth_minutos($minutosAuthSalida);
    $minutosAuthCompensada = ht_redondear_auth_minutos($minutosAuthCompensada);
    $minutosTotalAPagar = $minutosHorasTrab - $minutosAuthSalida + $minutosAuthCompensada + $minutosLicenciaConGoce;

    $horasTrab = intdiv($minutosHorasTrab, 60);
    $restoTrab = $minutosHorasTrab % 60;
    $horasFeriados = intdiv($minutosFeriados, 60);
    $horasTotal = intdiv($minutosTotalAPagar, 60);
    $restoTotal = $minutosTotalAPagar % 60;

    return [
        'legajo' => (int) ($empleado['Legajo'] ?? 0),
        'nombre' => (string) ($empleado['Nombre'] ?? ''),
        'apellido' => (string) ($empleado['Apellido'] ?? ''),
        'foto' => (string) ($empleado['Foto'] ?? ''),
        'estado' => (int) ($empleado['Estado'] ?? 1),
        'fechaBaja' => ht_format_fecha_baja($empleado['FechaBaja'] ?? null),
        'horasTrabajadasFormateadas' => sprintf('%02d:%02d', $horasTrab, $restoTrab),
        'diasTrabajados' => $diasTrabajados,
        'authSalidaFormateada' => intdiv($minutosAuthSalida, 60) . ' hs.',
        'authSalidaCompensadaFormateada' => intdiv($minutosAuthCompensada, 60) . ' hs.',
        'horasFeriadosFormateadas' => $horasFeriados . ' hs.',
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
        'minutosFeriados' => $minutosFeriados,
        'minutosExtra50' => $minutosExtra50,
        'minutosExtra100' => $minutosExtra100,
        'minutosTotalAPagar' => $minutosTotalAPagar,
        'minutosLicenciaConGoce' => $minutosLicenciaConGoce,
    ];
}

/** @param array<string, mixed> $empleado */
function horas_trabajadas_resumen_vacio(array $empleado): array
{
    return horas_trabajadas_armar_respuesta($empleado, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, false);
}

/** @param array<string, mixed> $empleado */
function horas_trabajadas_calcular_minutos_auth(array $empleado, array $datos): float
{
    $personalId = (string) ($empleado['Id'] ?? '');
    $areaNombre = (string) ($empleado['AreaNombre'] ?? $empleado['Area'] ?? '');
    $horarioIngreso = $empleado['HorarioIngreso'] ?? null;
    $horarioSalida = $empleado['HorarioSalida'] ?? null;
    if ($personalId === '' || !isset($datos['authPorPersonal'][$personalId])) {
        return 0.0;
    }

    $minutosAuth = 0.0;
    foreach ($datos['authPorPersonal'][$personalId] as $a) {
        if (!filter_var($a['RequiereRecupero'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            continue;
        }

        if (!empty($a['FechaHoraSalidaEfectiva'])) {
            $salida = new DateTimeImmutable((string) $a['FechaHoraSalidaEfectiva']);
        } else {
            $salida = new DateTimeImmutable((string) $a['Fecha'] . ' ' . substr((string) $a['HoraSalida'], 0, 8));
        }

        if (!empty($a['FechaHoraRegresoEfectiva'])) {
            $regreso = new DateTimeImmutable((string) $a['FechaHoraRegresoEfectiva']);
        } else {
            $fecha = new DateTimeImmutable((string) $a['Fecha']);
            $fichadasDia = ht_obtener_entrada_salida_real_dia($datos['fichadas'], $fecha);
            $regreso = ht_obtener_regreso_por_fin_de_turno(
                $datos['turnos'],
                $fecha,
                $salida,
                new DateTimeImmutable((string) $a['FechaRegreso'] . ' ' . substr((string) $a['HoraRegreso'], 0, 8)),
                $areaNombre,
                is_string($horarioIngreso) ? $horarioIngreso : null,
                is_string($horarioSalida) ? $horarioSalida : null,
                $fichadasDia['entrada'],
                $fichadasDia['salida']
            );
        }

        $minutosAuth += ht_redondear_auth_minutos((int) max(0.0, ($regreso->getTimestamp() - $salida->getTimestamp()) / 60.0));
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
    $compensable = 0.0;
    $feriadosTrabajados = 0.0;
    ht_acumular_extras_manuales($personalId, $datos['extrasManuales'], $e50, $e100);

    $desdeStr = $periodo['desde']->format('Y-m-d');
    $hastaStr = $periodo['hasta']->format('Y-m-d');
    $horarioIngresoNorm = ht_parse_horario_empleado(is_string($empleado['HorarioIngreso'] ?? null) ? $empleado['HorarioIngreso'] : null);
    $horarioSalidaNorm = ht_parse_horario_empleado(is_string($empleado['HorarioSalida'] ?? null) ? $empleado['HorarioSalida'] : null);
    $porDia = array_filter(registro_personal_agrupar_jornadas($fichadasEmp, $horarioIngresoNorm, $horarioSalidaNorm),
        static fn(string $fecha): bool => $fecha >= $desdeStr && $fecha <= $hastaStr, ARRAY_FILTER_USE_KEY);

    foreach ($porDia as $fechaKey => $grupo) {
        usort($grupo, static fn ($a, $b) => $a['Fecha'] <=> $b['Fecha']);
        $entrada = $grupo[0]['Fecha'];
        $salida = $grupo[count($grupo) - 1]['Fecha'];
        $authsDia = ht_auths_dia($datos, $personalId, $fechaKey);
        $inicioAuthPosterior = ht_inicio_auth_posterior($authsDia, $entrada);
        if ($inicioAuthPosterior !== null && $inicioAuthPosterior > $salida) {
            $salida = $inicioAuthPosterior;
        }
        ht_procesar_grupo_dia_fichadas(
            $fechaKey,
            $entrada,
            $salida,
            count($grupo),
            $periodo,
            $datos,
            $empleado,
            $authsDia,
            $diasTrabajo,
            $e50,
            $e100,
            $compensable,
            $feriadosTrabajados,
            $inicioAuthPosterior !== null,
            $horasExtrasFlag
        );
    }

    $e50 = (float) ht_redondear_hora_extra_completa($e50);
    $e100 = (float) ht_redondear_hora_extra_completa($e100);
    if (ht_es_quincenal((string) ($empleado['TipoContratacionNombre'] ?? ''))) {
        $fechasConFichada = array_fill_keys(array_keys($porDia), true);
        $e50 += ht_minutos_extras50_feriados_fin_de_semana_quincenal(
            $datos['feriados'],
            $periodo['desde'],
            $periodo['hasta'],
            $personalId,
            $fechasConFichada,
            $datos['licenciasCgs'],
            $datos['vacaciones'],
            $datos['ausenciasResta']
        );
    }
    $compensable = (float) ht_redondear_hora_extra_completa($compensable);

    // Compensación de auth. salida: siempre desde horas acumuladas en fichadas (independiente de HorasExtras).
    $deuda = $minutosAuth;
    $comp = min($compensable, $deuda);

    if ($horasExtrasFlag && $comp > 0) {
        $descuento50 = min($e50, $comp);
        $e50 -= $descuento50;
        $pendienteDescuento = $comp - $descuento50;
        if ($pendienteDescuento > 0) {
            $e100 -= min($e100, $pendienteDescuento);
        }
    }

    return [$e50, $e100, $comp, (int) $feriadosTrabajados];
}

function ht_procesar_grupo_dia_fichadas(
    string $fechaKey,
    DateTimeImmutable $entrada,
    DateTimeImmutable $salida,
    int $cantidadFichadas,
    array $periodo,
    array $datos,
    array $empleado,
    array $authsDia,
    array &$diasTrabajo,
    float &$e50,
    float &$e100,
    float &$compensable,
    float &$feriadosTrabajados,
    bool $tieneAuthPosterior,
    bool $calcularHorasExtras
): void {
    $personalId = (string) ($empleado['Id'] ?? '');
    $areaNombre = (string) ($empleado['AreaNombre'] ?? $empleado['Area'] ?? '');
    $horarioIngreso = is_string($empleado['HorarioIngreso'] ?? null) ? $empleado['HorarioIngreso'] : null;
    $horarioSalida = is_string($empleado['HorarioSalida'] ?? null) ? $empleado['HorarioSalida'] : null;
    $bloqueMinutos = (int) ($datos['config']['fraccionamiento_minutos'] ?? 60);
    $diaExtra50ConTope = 0.0;
    $diaExtra100ConTope = 0.0;
    $diaCompensableConTope = 0.0;

    $dia = $entrada;
    $hoyStr = $periodo['hoy']->format('Y-m-d');
    $diffMin = ($salida->getTimestamp() - $entrada->getTimestamp()) / 60.0;

    if ($diffMin < 30 && !$tieneAuthPosterior && $fechaKey !== $hoyStr) {
        return;
    }

    $dow = (int) $dia->format('w');
    $esFeriado = isset($datos['feriados'][$fechaKey]);
    if ($esFeriado) {
        $minutosFeriadoTrabajado = ht_redondear_bloque(ht_minutos_sin_segundos($entrada, $salida), $bloqueMinutos);
        if (ht_es_quincenal((string) ($empleado['TipoContratacionNombre'] ?? ''))) {
            $diasTrabajo[$fechaKey] = 'feriado_quincenal_trabajado';
        }
        $feriadosTrabajados += $minutosFeriadoTrabajado;
        $e100 += $minutosFeriadoTrabajado;
        return;
    }

    if ($dow === 0 || $dow === 6) {
        ht_acumular_tiempo_compensable($entrada, $salida, $compensable, $bloqueMinutos);
        ht_acumular_extras_segun_franja($entrada, $salida, $e50, $e100, $bloqueMinutos);
        return;
    }

    if ($dow === 1 && $entrada->format('H:i:s') < HT_FIN_EXTRA_LUNES) {
        $corte = $dia->setTime(4, 0);
        $finTramo = $salida < $corte ? $salida : $corte;
        ht_acumular_tiempo_compensable($entrada, $finTramo, $compensable, $bloqueMinutos);
        ht_acumular_extras_segun_franja($entrada, $finTramo, $e50, $e100, $bloqueMinutos);
        if ($salida <= $corte) {
            return;
        }
        $entrada = $corte;
    }

    // Una salida autorizada puede completar la jornada fuera del establecimiento.
    // Su regreso orienta el turno, pero no genera extras por fichadas inexistentes.
    $salidaReferencia = $salida;
    foreach ($authsDia as $auth) {
        $fechaRegreso = !empty($auth['FechaHoraRegresoEfectiva'])
            ? (string) $auth['FechaHoraRegresoEfectiva']
            : (string) ($auth['FechaRegreso'] ?? '') . ' ' . substr((string) ($auth['HoraRegreso'] ?? ''), 0, 8);
        if (trim($fechaRegreso) === '') {
            continue;
        }
        $regreso = new DateTimeImmutable($fechaRegreso);
        $duracion = ($regreso->getTimestamp() - $entrada->getTimestamp()) / 3600;
        if ($regreso > $salidaReferencia && $duracion <= (float) ($datos['config']['max_duracion_jornada_horas'] ?? 13)) {
            $salidaReferencia = $regreso;
        }
    }
    $turno = ht_obtener_turno_asignado($datos['turnos'], $entrada, $salidaReferencia, $dia, $areaNombre, $horarioIngreso, $horarioSalida);
    if ($turno['turno'] === null) {
        return;
    }

    $iniAsig = $turno['inicio'];
    $finAsig = $turno['fin'];
    $inicioNormal = $entrada > $iniAsig ? $entrada : $iniAsig;
    $finNormal = $salida < $finAsig ? $salida : $finAsig;

    if ($finNormal <= $inicioNormal && $authsDia === []) {
        return;
    }

    $minutosJornada = ht_minutos_sin_segundos($iniAsig, $finAsig);
    $turnoRow = $turno['turno'];
    if ($turnoRow['Entrada'] === HT_TURNO_ESPECIAL_ENTRADA && $turnoRow['Salida'] === HT_TURNO_ESPECIAL_SALIDA && $dow !== 5) {
        $minutosJornada += 15;
    }
    $minutosCubiertosPorAuth = ht_minutos_cubiertos_por_auth_hasta_fin_turno(
        $authsDia,
        $datos['turnos'],
        $iniAsig,
        $finAsig,
        $inicioNormal,
        $finNormal,
        $areaNombre,
        $horarioIngreso,
        $horarioSalida
    );

    $minutosTarde = ($entrada->getTimestamp() - $iniAsig->getTimestamp()) / 60.0;
    $inicioFaltante = $salida > $iniAsig ? $salida : $iniAsig;
    $minutosAntes = ($finAsig->getTimestamp() - $inicioFaltante->getTimestamp()) / 60.0 - $minutosCubiertosPorAuth;
    $minutosNormalesHoy = max(0, $minutosJornada
        - ht_descuento_registro_personal($minutosTarde)
        - ht_descuento_registro_personal($minutosAntes));

    if ($dow === 5 && $minutosNormalesHoy > 480) {
        $minutosExtra = $minutosNormalesHoy - 480;
        $minutosNormalesHoy = 480.0;
        if ($calcularHorasExtras && $minutosExtra >= 60) {
            $diaExtra50ConTope += (int) ($minutosExtra / 60) * 60;
        }
    }

    $diaExcluido = ht_dia_excluido_por_licencia_formal(
        $personalId,
        $fechaKey,
        $datos['licenciasCgs'],
        $datos['vacaciones'],
        $datos['ausenciasResta']
    );

    if (!$diaExcluido && $minutosNormalesHoy > 0) {
        $anteriores = $diasTrabajo[$fechaKey]['minutosNormales'] ?? 0;
        $maximoDia = ht_minutos_horas_trabajadas_dia_laboral($dia, $datos['feriados'], $areaNombre,
            (int) ($datos['config']['jornada_vigilancia_horas'] ?? 12));
        $diasTrabajo[$fechaKey] = ['minutosNormales' => min($maximoDia, $anteriores + (int) $minutosNormalesHoy)];
    }

    $limite = $dow === 6 ? HT_LIMITE_EXTRA_SABADO : HT_LIMITE_EXTRA_SEMANA;
    if ($entrada < $iniAsig) {
        $finExtraEntrada = $salida < $iniAsig ? $salida : $iniAsig;
        ht_acumular_extra_por_horario($entrada, $finExtraEntrada, $limite, $diaExtra50ConTope, $diaExtra100ConTope, $bloqueMinutos);
        ht_acumular_tiempo_compensable($entrada, $finExtraEntrada, $diaCompensableConTope, $bloqueMinutos);
    }
    if ($salida > $finAsig) {
        ht_acumular_extra_por_horario($finAsig, $salida, $limite, $diaExtra50ConTope, $diaExtra100ConTope, $bloqueMinutos);
        ht_acumular_tiempo_compensable($finAsig, $salida, $diaCompensableConTope, $bloqueMinutos);
    }

    ht_aplicar_tope_horas_extras_por_dia($datos, $diaExtra50ConTope, $diaExtra100ConTope, $diaCompensableConTope);
    if ($calcularHorasExtras) {
        $e50 += $diaExtra50ConTope;
        $e100 += $diaExtra100ConTope;
    }
    $compensable += $diaCompensableConTope;
}

function ht_auths_dia(array $datos, string $personalId, string $fechaKey): array
{
    $auths = $datos['authPorPersonal'][$personalId] ?? [];
    return array_values(array_filter($auths, static fn (array $a): bool => (string) $a['Fecha'] === $fechaKey));
}

function ht_inicio_auth_posterior(array $authsDia, DateTimeImmutable $entrada): ?DateTimeImmutable
{
    $posteriores = [];
    foreach ($authsDia as $a) {
        $salida = !empty($a['FechaHoraSalidaEfectiva'])
            ? new DateTimeImmutable((string) $a['FechaHoraSalidaEfectiva'])
            : new DateTimeImmutable((string) $a['Fecha'] . ' ' . substr((string) $a['HoraSalida'], 0, 8));

        if ($salida > $entrada) {
            $posteriores[] = $salida;
        }
    }

    usort($posteriores, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
    return $posteriores[0] ?? null;
}

function ht_minutos_cubiertos_por_auth_hasta_fin_turno(
    array $authsDia,
    array $turnos,
    DateTimeImmutable $inicioTurno,
    DateTimeImmutable $finTurno,
    DateTimeImmutable $inicioNormal,
    DateTimeImmutable $finNormal,
    string $areaNombre,
    ?string $horarioIngreso,
    ?string $horarioSalida
): float {
    $intervalos = [[$inicioNormal, $finNormal]];

    foreach ($authsDia as $a) {
        $fecha = new DateTimeImmutable((string) $a['Fecha']);
        $salida = !empty($a['FechaHoraSalidaEfectiva'])
            ? new DateTimeImmutable((string) $a['FechaHoraSalidaEfectiva'])
            : new DateTimeImmutable((string) $a['Fecha'] . ' ' . substr((string) $a['HoraSalida'], 0, 8));

        if (!empty($a['FechaHoraRegresoEfectiva'])) {
            $regreso = new DateTimeImmutable((string) $a['FechaHoraRegresoEfectiva']);
        } else {
            $regreso = ht_obtener_regreso_por_fin_de_turno(
                $turnos,
                $fecha,
                $salida,
                new DateTimeImmutable((string) $a['FechaRegreso'] . ' ' . substr((string) $a['HoraRegreso'], 0, 8)),
                $areaNombre,
                $horarioIngreso,
                $horarioSalida,
                $inicioTurno,
                $finTurno
            );
        }

        $inicio = $salida < $inicioTurno ? $inicioTurno : $salida;
        $fin = $regreso > $finTurno ? $finTurno : $regreso;

        if ($regreso >= $finTurno && $fin > $inicio) {
            $intervalos[] = [$inicio, $fin];
        }
    }

    $normal = max(0.0, ($finNormal->getTimestamp() - $inicioNormal->getTimestamp()) / 60.0);
    $cubierto = ht_sumar_intervalos_minutos($intervalos);
    return max(0.0, $cubierto - $normal);
}

function ht_sumar_intervalos_minutos(array $intervalos): float
{
    $validos = array_values(array_filter($intervalos, static fn (array $i): bool => $i[1] > $i[0]));
    usort($validos, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

    if ($validos === []) {
        return 0.0;
    }

    $minutos = 0.0;
    [$inicioActual, $finActual] = $validos[0];

    foreach (array_slice($validos, 1) as [$inicio, $fin]) {
        if ($inicio <= $finActual) {
            if ($fin > $finActual) {
                $finActual = $fin;
            }
            continue;
        }

        $minutos += ($finActual->getTimestamp() - $inicioActual->getTimestamp()) / 60.0;
        $inicioActual = $inicio;
        $finActual = $fin;
    }

    $minutos += ($finActual->getTimestamp() - $inicioActual->getTimestamp()) / 60.0;
    return $minutos;
}

function ht_segundos_del_dia(DateTimeImmutable $dt): int
{
    return (int) $dt->format('H') * 3600 + (int) $dt->format('i') * 60 + (int) $dt->format('s');
}

function ht_obtener_entrada_salida_real_dia(array $fichadasEmp, DateTimeImmutable $fecha): array
{
    $fechaKey = $fecha->format('Y-m-d');
    $dia = [];
    foreach ($fichadasEmp as $f) {
        if ($f['Fecha']->format('Y-m-d') === $fechaKey) {
            $dia[] = $f['Fecha'];
        }
    }

    if (count($dia) === 0) {
        return ['entrada' => null, 'salida' => null];
    }

    usort($dia, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
    $entrada = $dia[0];
    if (count($dia) < 2) {
        return ['entrada' => $entrada, 'salida' => null];
    }

    $salida = $dia[count($dia) - 1];

    return (($salida->getTimestamp() - $entrada->getTimestamp()) / 60.0) >= 5
        ? ['entrada' => $entrada, 'salida' => $salida]
        : ['entrada' => null, 'salida' => null];
}

function ht_tiene_fichada_incompleta(
    array $fichadasEmp,
    array $turnos,
    array $feriados,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta,
    DateTimeImmutable $hoy,
    string $personalId,
    string $areaNombre,
    mixed $horarioIngreso,
    mixed $horarioSalida,
    array $authSalidaLookup,
    array $licenciasLookup
): bool {
    if ($fichadasEmp === []) {
        return false;
    }

    $hoyStr = $hoy->format('Y-m-d');
    $desdeStr = $desde->format('Y-m-d');
    $hastaStr = $hasta->format('Y-m-d');
    $horarioIngresoNorm = ht_parse_horario_empleado(is_string($horarioIngreso) ? $horarioIngreso : null);
    $horarioSalidaNorm = ht_parse_horario_empleado(is_string($horarioSalida) ? $horarioSalida : null);
    $porDia = registro_personal_agrupar_jornadas($fichadasEmp, $horarioIngresoNorm, $horarioSalidaNorm);

    foreach ($porDia as $fechaKey => $dia) {
        if ($fechaKey < $desdeStr || $fechaKey > $hastaStr) {
            continue;
        }
        if (isset($feriados[$fechaKey])) {
            continue;
        }
        if ($fechaKey >= $hoyStr) {
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

        if (count($dia) === 1 || $diff < 5) {
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
        $total += (int) $lic['HastaDate']->diff($lic['DesdeDate'])->days + 1;
    }
    return $total;
}

function ht_descuento_registro_personal(float $minutos): int
{
    return $minutos <= TOLERANCIA_MINUTOS_REGISTRO_PERSONAL ? 0 : (int) ceil($minutos / 60) * 60;
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
    array $ausenciasResta,
    ?string $areaNombre = null,
    int $jornadaVigilanciaHoras = 12
): int {
    $m = 0;
    foreach ($diasTrabajo as $fechaStr => $tipoDia) {
        // Los feriados se informan por separado; no integran las horas normales ni su total.
        if (isset($feriados[$fechaStr]) || in_array($tipoDia, [
            'feriado_trabajado', 'feriado_quincenal_trabajado', 'feriado_no_trabajado',
        ], true)) {
            continue;
        }
        $d = new DateTimeImmutable($fechaStr);
        $maximoDia = ht_minutos_horas_trabajadas_dia_laboral($d, $feriados, $areaNombre, $jornadaVigilanciaHoras);
        $m += is_array($tipoDia)
            ? min($maximoDia, max(0, (int) ($tipoDia['minutosNormales'] ?? 0)))
            : $maximoDia;
    }
    return $m;
}

function ht_sumar_minutos_feriados_no_trabajados(
    array $diasTrabajo,
    array $feriados,
    ?string $areaNombre = null,
    int $jornadaVigilanciaHoras = 12
): int {
    $m = 0;
    foreach ($diasTrabajo as $fechaStr => $tipoDia) {
        if ($tipoDia !== 'feriado_no_trabajado') {
            continue;
        }
        $m += ht_minutos_horas_trabajadas_dia_laboral(
            new DateTimeImmutable((string) $fechaStr),
            $feriados,
            $areaNombre,
            $jornadaVigilanciaHoras
        );
    }
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

function ht_minutos_horas_trabajadas_dia_laboral(
    DateTimeImmutable $fecha,
    array $feriados,
    ?string $areaNombre = null,
    int $jornadaVigilanciaHoras = 12
): int
{
    $dow = (int) $fecha->format('w');
    if ($dow === 0 || $dow === 6) {
        return 0;
    }
    if (ht_es_area_vigilancia((string) $areaNombre)) {
        return $jornadaVigilanciaHoras * 60;
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

function ht_agregar_feriados_fin_de_semana_quincenal(
    array &$destino,
    array $feriados,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta,
    bool $aplicar,
    string $personalId,
    array $licenciasCgs,
    array $vacaciones,
    array $ausenciasResta
): void {
    if (!$aplicar) {
        return;
    }

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

        $destino[$fechaStr] = true;
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

function ht_agregar_feriados_habiles_no_trabajados_quincenal(
    array &$diasTrabajo,
    array $feriados,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta,
    string $personalId,
    array $licenciasCgs,
    array $vacaciones,
    array $ausenciasResta
): void {
    foreach (array_keys($feriados) as $fechaStr) {
        $f = new DateTimeImmutable($fechaStr);
        if ($f < $desde || $f > $hasta) {
            continue;
        }
        $dow = (int) $f->format('w');
        if ($dow === 0 || $dow === 6) {
            continue;
        }
        if (isset($diasTrabajo[$fechaStr])) {
            continue;
        }
        if (ht_dia_excluido_por_licencia_formal($personalId, $fechaStr, $licenciasCgs, $vacaciones, $ausenciasResta)) {
            continue;
        }

        $diasTrabajo[$fechaStr] = 'feriado_no_trabajado';
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

function ht_minutos_feriados_habiles_quincenal(
    array $feriados,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta,
    string $personalId,
    array $fechasConJornadaNormal,
    array $licenciasCgs,
    array $vacaciones,
    array $ausenciasResta,
    string $areaNombre,
    int $jornadaVigilanciaHoras
): int {
    $m = 0;
    foreach (array_keys($feriados) as $fechaStr) {
        $f = new DateTimeImmutable($fechaStr);
        if ($f < $desde || $f > $hasta) {
            continue;
        }
        $dow = (int) $f->format('w');
        if ($dow === 0 || $dow === 6) {
            continue;
        }
        if (isset($fechasConJornadaNormal[$fechaStr])) {
            continue;
        }
        if (ht_dia_excluido_por_licencia_formal($personalId, $fechaStr, $licenciasCgs, $vacaciones, $ausenciasResta)) {
            continue;
        }
        $m += ht_minutos_horas_trabajadas_dia_laboral($f, $feriados, $areaNombre, $jornadaVigilanciaHoras);
    }
    return $m;
}

function ht_minutos_extras50_feriados_fin_de_semana_quincenal(
    array $feriados,
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta,
    string $personalId,
    array $fechasConFichada,
    array $licenciasCgs,
    array $vacaciones,
    array $ausenciasResta
): int {
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
        if (isset($fechasConFichada[$fechaStr])) {
            continue;
        }
        if (ht_dia_excluido_por_licencia_formal($personalId, $fechaStr, $licenciasCgs, $vacaciones, $ausenciasResta)) {
            continue;
        }
        $m += 9 * 60;
    }
    return $m;
}

function ht_aplicar_tope_horas_extras_por_dia(array $datos, float &$extra50, float &$extra100, float &$compensable): void
{
    $maximoHoras = (int) ($datos['config']['maximo_horas_extras_dia'] ?? HT_MAXIMO_HORAS_EXTRAS_DIA);
    $maximo = $maximoHoras * 60;
    $total = $extra50 + $extra100;
    if ($total <= $maximo) {
        $compensable = min($compensable, $maximo);
        return;
    }

    $extra100Topeada = min($extra100, $maximo);
    $extra50Topeada = min($extra50, $maximo - $extra100Topeada);
    $extra50 = $extra50Topeada;
    $extra100 = $extra100Topeada;
    $compensable = min($compensable, $maximo);
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
    float &$e100,
    int $bloqueMinutos = 60
): void {
    if ($salida <= $entrada) {
        return;
    }

    $dow = (int) $entrada->format('w');
    if ($dow === 0) {
        $e100 += ht_redondear_bloque(($salida->getTimestamp() - $entrada->getTimestamp()) / 60.0, $bloqueMinutos);
        return;
    }

    if ($dow === 1 && $entrada->format('H:i:s') < HT_FIN_EXTRA_LUNES) {
        $corte = $entrada->setTime(4, 0);
        $finTramo = $salida < $corte ? $salida : $corte;
        $e100 += ht_redondear_bloque(($finTramo->getTimestamp() - $entrada->getTimestamp()) / 60.0, $bloqueMinutos);
        if ($salida <= $corte) {
            return;
        }
        $entrada = $corte;
        $dow = (int) $entrada->format('w');
    }

    $limite = $dow === 6 ? HT_LIMITE_EXTRA_SABADO : HT_LIMITE_EXTRA_SEMANA;
    ht_acumular_extra_por_horario($entrada, $salida, $limite, $e50, $e100, $bloqueMinutos);
}

function ht_acumular_extra_por_horario(
    DateTimeImmutable $inicioExtra,
    DateTimeImmutable $finExtra,
    string $limiteHorario,
    float &$extra50,
    float &$extra100,
    int $bloqueMinutos = 60
): void {
    if ($finExtra <= $inicioExtra) {
        return;
    }

    $parts = explode(':', $limiteHorario);
    $corte = $inicioExtra->setTime((int) $parts[0], (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0));

    if ($finExtra <= $corte) {
        $extra50 += ht_redondear_bloque(ht_minutos_sin_segundos($inicioExtra, $finExtra), $bloqueMinutos);
        return;
    }
    if ($inicioExtra >= $corte) {
        $extra100 += ht_redondear_bloque(ht_minutos_sin_segundos($inicioExtra, $finExtra), $bloqueMinutos);
        return;
    }

    $extra50 += ht_redondear_bloque(ht_minutos_sin_segundos($inicioExtra, $corte), $bloqueMinutos);
    $extra100 += ht_redondear_bloque(ht_minutos_sin_segundos($corte, $finExtra), $bloqueMinutos);
}

function ht_acumular_tiempo_compensable(
    DateTimeImmutable $inicio,
    DateTimeImmutable $fin,
    float &$compensable,
    int $bloqueMinutos = 60
): void {
    $compensable += ht_redondear_bloque(ht_minutos_sin_segundos($inicio, $fin), $bloqueMinutos);
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
    return ht_redondear_bloque($minutos, 60);
}

function ht_redondear_bloque(float $minutos, int $bloqueMinutos): int
{
    if ($minutos <= 0) {
        return 0;
    }
    $bloque = max(1, $bloqueMinutos);
    return (int) ($minutos / $bloque) * $bloque;
}

function ht_redondear_auth_minutos(int $minutos): int
{
    if ($minutos === 0) {
        return 0;
    }
    return (int) ceil($minutos / 60.0) * 60;
}

function ht_parse_horario_empleado(?string $horario): ?string
{
    $horario = trim((string) $horario);
    if ($horario === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('!H:i', $horario)
        ?: DateTimeImmutable::createFromFormat('!G:i', $horario)
        ?: DateTimeImmutable::createFromFormat('!H:i:s', $horario)
        ?: DateTimeImmutable::createFromFormat('!G:i:s', $horario);

    return $dt instanceof DateTimeImmutable ? $dt->format('H:i:s') : null;
}

function ht_obtener_turno_asignado(
    array $turnos,
    DateTimeImmutable $entrada,
    DateTimeImmutable $salida,
    DateTimeImmutable $dia,
    string $areaNombre,
    ?string $horarioIngreso = null,
    ?string $horarioSalida = null
): array {
    $turnoAsignado = null;
    $menorDif = PHP_FLOAT_MAX;
    $iniAsig = null;
    $finAsig = null;
    $esLogistica = ht_es_area_logistica($areaNombre);
    $esViernes = (int) $dia->format('w') === 5;
    $ingresoEmpleado = ht_parse_horario_empleado($horarioIngreso);
    $salidaEmpleado = ht_parse_horario_empleado($horarioSalida);
    $mejorCoincideHorarioEmpleado = false;

    foreach ($turnos as $t) {
        if (!$esLogistica && ht_es_turno_logistica($t)) {
            continue;
        }
        $horario = ht_obtener_horario_evaluacion_turno($t, $esViernes);
        $ini = ht_fecha_con_hora($dia, $horario['Entrada']);
        $fin = ht_fecha_con_hora($dia, $horario['Salida']);
        if ($fin <= $ini) {
            $fin = $fin->modify('+1 day');
        }

        $difEntrada = abs(($entrada->getTimestamp() - $ini->getTimestamp()) / 60);
        $difSalida = abs(($salida->getTimestamp() - $fin->getTimestamp()) / 60);
        $dif = $difEntrada + ($difSalida * 2);
        $coincideHorarioEmpleado = $ingresoEmpleado !== null
            && $salidaEmpleado !== null
            && $t['Entrada'] === $ingresoEmpleado
            && $t['Salida'] === $salidaEmpleado;

        if ($dif < $menorDif || ($dif === $menorDif && $coincideHorarioEmpleado && !$mejorCoincideHorarioEmpleado)) {
            $menorDif = $dif;
            $turnoAsignado = $t;
            $iniAsig = $ini;
            $finAsig = $fin;
            $mejorCoincideHorarioEmpleado = $coincideHorarioEmpleado;
        }
    }

    return ['turno' => $turnoAsignado, 'inicio' => $iniAsig, 'fin' => $finAsig];
}

function ht_fecha_con_hora(DateTimeImmutable $fecha, string $hora): DateTimeImmutable
{
    return $fecha->setTime(
        (int) substr($hora, 0, 2),
        (int) substr($hora, 3, 2),
        (int) substr($hora, 6, 2)
    );
}

function ht_obtener_regreso_por_fin_de_turno(
    array $turnos,
    DateTimeImmutable $fecha,
    DateTimeImmutable $salidaAutorizada,
    DateTimeImmutable $regresoInformado,
    string $areaNombre,
    ?string $horarioIngreso = null,
    ?string $horarioSalida = null,
    ?DateTimeImmutable $entradaRealDia = null,
    ?DateTimeImmutable $salidaRealDia = null
): DateTimeImmutable
{
    $dia = $fecha->setTime(0, 0);
    $salidaReferencia = $regresoInformado <= $salidaAutorizada
        ? $regresoInformado->modify('+1 day')
        : $regresoInformado;

    if ($entradaRealDia !== null) {
        $salidaTurno = $salidaRealDia !== null && $salidaRealDia > $entradaRealDia
            ? $salidaRealDia
            : $salidaAutorizada;
        $turnoReal = ht_obtener_turno_asignado(
            $turnos,
            $entradaRealDia,
            $salidaTurno,
            $fecha,
            $areaNombre,
            $horarioIngreso,
            $horarioSalida
        );
        if ($turnoReal['fin'] !== null) {
            return $turnoReal['fin'];
        }
    }

    $ingresoEmpleado = ht_parse_horario_empleado($horarioIngreso);
    $salidaEmpleado = ht_parse_horario_empleado($horarioSalida);
    if ($ingresoEmpleado !== null && $salidaEmpleado !== null) {
        $inicioHorario = ht_fecha_con_hora($dia, $ingresoEmpleado);
        $finHorario = ht_fecha_con_hora($dia, $salidaEmpleado);
        if ($finHorario <= $inicioHorario) {
            $finHorario = $finHorario->modify('+1 day');
        }

        $turnoHorario = ht_obtener_turno_asignado($turnos, $inicioHorario, $finHorario, $fecha, $areaNombre, $horarioIngreso, $horarioSalida);
        if ($turnoHorario['fin'] !== null) {
            return $turnoHorario['fin'];
        }
    }

    $turno = ht_obtener_turno_asignado($turnos, $salidaAutorizada, $salidaReferencia, $fecha, $areaNombre, $horarioIngreso, $horarioSalida);
    if ($turno['fin'] === null) {
        return $salidaReferencia;
    }
    return $turno['fin'];
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

function ht_es_area_vigilancia(string $areaNombre): bool
{
    if ($areaNombre === '') {
        return false;
    }
    $normalizado = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $areaNombre) ?: $areaNombre;
    return strtoupper(trim($normalizado)) === 'VIGILANCIA';
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

function ht_es_horario_nocturno(?string $horarioIngreso, ?string $horarioSalida): bool
{
    if ($horarioIngreso === null || $horarioSalida === null) {
        return false;
    }
    return ht_time_to_seconds($horarioSalida) <= ht_time_to_seconds($horarioIngreso);
}

function ht_obtener_fecha_laboral_fichada(
    DateTimeImmutable $fichada,
    ?string $horarioIngreso,
    ?string $horarioSalida
): string {
    return registro_personal_fecha_laboral($fichada, $horarioIngreso, $horarioSalida);
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
