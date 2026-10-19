<?php
declare(strict_types=1);

require_once __DIR__ . '/reportes_sgi.php';
require_once __DIR__ . '/auth_salida_daily_report.php';

const HEMP_ROL = 'CENTRO_OPERACIONES_MOTIVO_HE';
const HEMP_FECHA_INICIO = '2026-10-01';

/** @return array{ok:bool,desde:string,hasta:string,usuariosEvaluados:int,enviados:int,omitidos:int,errores:int,detalle:list<array<string,mixed>>} */
function horas_extra_motivo_pendientes_enviar(PDO $pdo, array $config): array
{
    $desde = new DateTimeImmutable(HEMP_FECHA_INICIO);
    $hasta = new DateTimeImmutable('today');
    error_log('[horas_extra_motivo] Iniciando calculo de pendientes desde ' . $desde->format('Y-m-d') . ' hasta ' . $hasta->format('Y-m-d'));
    $pendientesPorArea = horas_extra_motivo_pendientes_por_area($pdo, $config, $desde, $hasta);
    error_log('[horas_extra_motivo] Areas con pendientes: ' . count($pendientesPorArea));
    $usuarios = horas_extra_motivo_usuarios_destinatarios($pdo);
    error_log('[horas_extra_motivo] Usuarios destinatarios encontrados: ' . count($usuarios));

    $resultado = [
        'ok' => true,
        'desde' => $desde->format('Y-m-d'),
        'hasta' => $hasta->format('Y-m-d'),
        'usuariosEvaluados' => count($usuarios),
        'enviados' => 0,
        'omitidos' => 0,
        'errores' => 0,
        'detalle' => [],
    ];

    $mailConfig = auth_salida_daily_report_config($config);
    foreach ($usuarios as $usuario) {
        $areas = [];
        $tienePendientes = false;
        foreach ($usuario['areas'] as $area) {
            $cantidad = (int) ($pendientesPorArea[$area['id']] ?? 0);
            if ($cantidad <= 0) {
                continue;
            }

            $areas[] = [
                'id' => $area['id'],
                'nombre' => $area['nombre'],
                'pendientes' => $cantidad,
            ];
            $tienePendientes = true;
        }

        if (!$tienePendientes) {
            $resultado['omitidos']++;
            continue;
        }

        $nombre = trim($usuario['nombre'] . ' ' . $usuario['apellido']);
        $html = horas_extra_motivo_mail_html($mailConfig, $nombre, $areas, $desde, $hasta);
        $texto = horas_extra_motivo_mail_texto($nombre, $areas, $desde, $hasta);
        $envio = horas_extra_motivo_send_html_mail(
            $mailConfig,
            $usuario['mail'],
            'Horas extras pendientes de tipificar',
            $html,
            $texto
        );
        $enviado = (bool) ($envio['ok'] ?? false);

        $resultado['detalle'][] = [
            'mail' => $usuario['mail'],
            'areas' => $areas,
            'enviado' => $enviado,
            'error' => $envio['error'] ?? null,
        ];

        if ($enviado) {
            $resultado['enviados']++;
        } else {
            $resultado['errores']++;
            $resultado['ok'] = false;
        }
    }

    return $resultado;
}

/** @return array<string,int> */
function horas_extra_motivo_pendientes_por_area(PDO $pdo, array $config, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
{
    $pendientes = [];
    foreach (horas_extra_motivo_calcular_diarias($pdo, $config, $desde, $hasta) as $extra) {
        $clave = $extra['personalId'] . '|' . $extra['fecha'];
        if (horas_extra_motivo_esta_tipificada($pdo, $clave, $extra['personalId'], $extra['fecha'])) {
            continue;
        }

        $areaId = $extra['areaId'];
        $pendientes[$areaId] = ($pendientes[$areaId] ?? 0) + 1;
    }

    return $pendientes;
}

/** @return list<array{personalId:string,areaId:string,fecha:string}> */
function horas_extra_motivo_calcular_diarias(PDO $pdo, array $config, DateTimeImmutable $desde, DateTimeImmutable $hasta, ?int $tipoContratacionId = null, array $areaIds = []): array
{
    $empleados = reportes_sgi_empleados($pdo, $desde, $hasta, $tipoContratacionId, null, $areaIds);
    $filas = [];

    foreach ($empleados as $empleado) {
        $personalId = (string) ($empleado['Id'] ?? '');
        $areaId = (string) ($empleado['ArealId'] ?? '');
        if ($personalId === '' || $areaId === '') {
            continue;
        }

        $empleado = horas_trabajadas_enriquecer_personal($pdo, $empleado);
        $periodo = horas_trabajadas_normalizar_periodo($desde, $hasta);
        $datos = horas_trabajadas_cargar_datos($pdo, $empleado, $periodo);
        $datos['config'] = ht_asistencia_calculo_config($config);

        if (ht_tiene_fichada_incompleta(
            $datos['fichadas'],
            $datos['turnos'],
            $datos['feriados'],
            $periodo['desde'],
            $periodo['hasta'],
            $periodo['hoy'],
            $personalId,
            (string) ($empleado['AreaNombre'] ?? ''),
            is_string($empleado['HorarioIngreso'] ?? null) ? $empleado['HorarioIngreso'] : null,
            is_string($empleado['HorarioSalida'] ?? null) ? $empleado['HorarioSalida'] : null,
            $datos['authSalidaLookup'],
            $datos['licenciasLookup']
        )) {
            continue;
        }

        $filas = array_merge($filas, horas_extra_motivo_diarias_empleado($periodo, $datos, $empleado, $areaId));
    }

    return $filas;
}

/** @return list<array{personalId:string,areaId:string,fecha:string}> */
function horas_extra_motivo_diarias_empleado(array $periodo, array $datos, array $empleado, string $areaId): array
{
    $personalId = (string) ($empleado['Id'] ?? '');
    $horasExtrasFlag = (bool) ($empleado['HorasExtras'] ?? false);
    $desdeStr = $periodo['desde']->format('Y-m-d');
    $hastaStr = $periodo['hasta']->format('Y-m-d');
    $horarioIngreso = ht_parse_horario_empleado(is_string($empleado['HorarioIngreso'] ?? null) ? $empleado['HorarioIngreso'] : null);
    $horarioSalida = ht_parse_horario_empleado(is_string($empleado['HorarioSalida'] ?? null) ? $empleado['HorarioSalida'] : null);
    $filas = [];
    $porDia = array_filter(registro_personal_agrupar_jornadas($datos['fichadas'], $horarioIngreso, $horarioSalida),
        static fn(string $fecha): bool => $fecha >= $desdeStr && $fecha <= $hastaStr, ARRAY_FILTER_USE_KEY);

    foreach ($porDia as $fechaKey => $grupo) {
        $fecha = new DateTimeImmutable($fechaKey);
        $esFeriado = isset($datos['feriados'][$fechaKey]);
        $dow = (int) $fecha->format('w');
        if (!$horasExtrasFlag && $dow !== 0 && $dow !== 6 && !$esFeriado) {
            continue;
        }

        usort($grupo, static fn (array $a, array $b): int => $a['Fecha'] <=> $b['Fecha']);
        $entrada = $grupo[0]['Fecha'];
        $salida = $grupo[count($grupo) - 1]['Fecha'];
        $authsDia = ht_auths_dia($datos, $personalId, $fechaKey);
        $inicioAuthPosterior = ht_inicio_auth_posterior($authsDia, $entrada);
        if ($inicioAuthPosterior !== null && $inicioAuthPosterior > $salida) {
            $salida = $inicioAuthPosterior;
        }

        $diasTrabajo = [];
        $extra50 = 0.0;
        $extra100 = 0.0;
        $compensable = 0.0;
        $feriadosTrabajados = 0.0;
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
            $extra50,
            $extra100,
            $compensable,
            $feriadosTrabajados,
            $inicioAuthPosterior !== null,
            $horasExtrasFlag
        );

        $m50 = ht_redondear_hora_extra_completa($extra50);
        $m100 = ht_redondear_hora_extra_completa($extra100);
        if ($m50 > 0 || $m100 > 0) {
            $filas[$fechaKey] = hemp_fila_diaria($empleado, $areaId, $fechaKey);
            $filas[$fechaKey]['minutosExtra50'] = $m50;
            $filas[$fechaKey]['minutosExtra100'] = $m100;
            $filas[$fechaKey]['minutosFichadas50'] = $m50;
            $filas[$fechaKey]['minutosFichadas100'] = $m100;
        }
    }

    foreach (($datos['extrasManuales'][$personalId] ?? []) as $manual) {
        $fechaKey = substr((string) ($manual['Fecha'] ?? ''), 0, 10);
        if ($fechaKey < $desdeStr || $fechaKey > $hastaStr) {
            continue;
        }

        $desdeManual = new DateTimeImmutable($fechaKey . ' ' . substr((string) $manual['Desde'], 0, 8));
        $hastaManual = new DateTimeImmutable($fechaKey . ' ' . substr((string) $manual['Hasta'], 0, 8));
        $extra50 = 0.0;
        $extra100 = 0.0;
        ht_acumular_extras_segun_franja($desdeManual, $hastaManual, $extra50, $extra100, (int) ($datos['config']['fraccionamiento_minutos'] ?? 60));
        $m50 = ht_redondear_hora_extra_completa($extra50);
        $m100 = ht_redondear_hora_extra_completa($extra100);
        if ($m50 > 0 || $m100 > 0) {
            $filas[$fechaKey] ??= hemp_fila_diaria($empleado, $areaId, $fechaKey);
            $filas[$fechaKey]['minutosExtra50'] += $m50;
            $filas[$fechaKey]['minutosExtra100'] += $m100;
            $filas[$fechaKey]['cargasManuales'][] = [
                'minutosExtra50' => $m50, 'minutosExtra100' => $m100,
                'motivo' => (string) ($manual['Motivo'] ?? ''),
                'motivoActivo' => (int) ($manual['MotivoEstado'] ?? 0) === 1,
            ];
        }
    }
    return array_values($filas);
}

function hemp_fila_diaria(array $empleado, string $areaId, string $fecha): array
{
    return [
        'personalId' => (string) $empleado['Id'], 'areaId' => $areaId, 'fecha' => $fecha,
        'legajo' => (int) ($empleado['Legajo'] ?? 0),
        'nombre' => (string) ($empleado['Nombre'] ?? ''), 'apellido' => (string) ($empleado['Apellido'] ?? ''),
        'areaNombre' => (string) ($empleado['AreaNombre'] ?? ''),
        'minutosExtra50' => 0, 'minutosExtra100' => 0,
        'minutosFichadas50' => 0, 'minutosFichadas100' => 0, 'cargasManuales' => [],
    ];
}

function horas_extra_motivo_esta_tipificada(PDO $pdo, string $cacheKey, string $personalId, string $fecha): bool
{
    static $cache = [];
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $stmt = $pdo->prepare(
        'SELECT 1 FROM HoraExtraMotivoValidaciones
         WHERE Estado = 1 AND PersonalId = :pid AND Fecha = :fecha
         LIMIT 1'
    );
    $stmt->execute(['pid' => $personalId, 'fecha' => $fecha]);
    $cache[$cacheKey] = (bool) $stmt->fetchColumn();

    return $cache[$cacheKey];
}

/** @return list<array{id:string,nombre:string,apellido:string,mail:string,areas:list<array{id:string,nombre:string}>}> */
function horas_extra_motivo_usuarios_destinatarios(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT u.Id, u.Nombre, u.Apellido, u.Mail, a.Id AS AreaId, a.Nombre AS AreaNombre
         FROM Usuarios u
         INNER JOIN RolUsuario ru ON ru.UsuariosId = u.Id
         INNER JOIN Roles r ON r.Id = ru.RolesId AND r.RolName = :rol
         INNER JOIN AreaUsuario au ON au.UsuariosId = u.Id
         INNER JOIN Area a ON a.Id = au.AreasId AND a.Estado = 1
         WHERE u.Estado = 1 AND TRIM(COALESCE(u.Mail, "")) <> ""
         ORDER BY u.Apellido, u.Nombre, a.Nombre'
    );
    $stmt->execute(['rol' => HEMP_ROL]);

    $usuarios = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (string) $row['Id'];
        if (!isset($usuarios[$id])) {
            $usuarios[$id] = [
                'id' => $id,
                'nombre' => (string) $row['Nombre'],
                'apellido' => (string) $row['Apellido'],
                'mail' => (string) $row['Mail'],
                'areas' => [],
            ];
        }
        $usuarios[$id]['areas'][] = [
            'id' => (string) $row['AreaId'],
            'nombre' => (string) $row['AreaNombre'],
        ];
    }

    return array_values($usuarios);
}

/** @param list<array{id:string,nombre:string,pendientes:int}> $areas */
function horas_extra_motivo_mail_html(array $mailConfig, string $nombre, array $areas, DateTimeImmutable $desde, DateTimeImmutable $hasta): string
{
    $logoPath = trim((string) ($mailConfig['mail_logo_path'] ?? ''));
    $logo = $logoPath !== '' && is_file($logoPath)
        ? '<div style="margin:0 0 24px;"><img src="cid:logo-raei-reset" alt="RAEI" style="display:inline-block;max-width:170px;height:auto;border:0;" /></div>'
        : '';
    $filas = '';
    $totalPendientes = 0;
    $rowIndex = 0;
    foreach ($areas as $area) {
        $cantidad = (int) $area['pendientes'];
        $totalPendientes += $cantidad;
        $rowBg = $rowIndex % 2 === 0 ? '#ffffff' : '#f9fafb';
        $filas .= '<tr style="background:' . $rowBg . ';">'
            . '<td style="padding:13px 16px;background:' . $rowBg . ';font-weight:600;color:#111827;border-bottom:1px solid #e5e7eb;">' . htmlspecialchars(trim((string) $area['nombre']), ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:13px 16px;background:' . $rowBg . ';text-align:right;border-bottom:1px solid #e5e7eb;">'
            . '<span style="display:inline-block;min-width:42px;padding:4px 10px;border-radius:999px;background:#eef4ff;color:#0d47a1;font-weight:700;text-align:center;">' . $cantidad . '</span>'
            . '</td>'
            . '</tr>';
        $rowIndex++;
    }

    return '<!DOCTYPE html><html lang="es"><body style="margin:0;padding:24px;background:#ffffff;color:#202124;font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5;">'
        . '<div style="max-width:680px;margin:0 auto;">'
        . $logo
        . '<p style="margin:0 0 12px;font-weight:700;">Buen día' . ($nombre !== '' ? ', ' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') : '') . '.</p>'
        . '<p style="margin:0 0 18px;">Hay registros de horas extras pendientes de tipificar en el SGI (Sistema de Gestión Interna).</p>'
        . '<p style="margin:0 0 18px;">Período evaluado: <strong>' . $desde->format('d/m/Y') . '</strong> al <strong>' . $hasta->format('d/m/Y') . '</strong>.</p>'
        . '<table cellpadding="0" cellspacing="0" role="presentation" style="width:560px;max-width:100%;border-collapse:separate;border-spacing:0;border:1px solid #d7dde6;border-radius:10px;overflow:hidden;background:#ffffff;box-shadow:0 4px 14px rgba(17,24,39,0.08);">'
        . '<tr><th colspan="2" style="padding:15px 18px;background:#dc3545;color:#ffffff;text-align:left;font-size:16px;font-weight:700;letter-spacing:0;">Cargas Pendientes</th></tr>'
        . '<tr>'
        . '<td style="padding:10px 16px;background:#f3f4f6;font-size:12px;font-weight:700;color:#4b5563;text-transform:uppercase;border-bottom:1px solid #e5e7eb;">Área</td>'
        . '<td style="padding:10px 16px;background:#f3f4f6;font-size:12px;font-weight:700;color:#4b5563;text-transform:uppercase;text-align:right;border-bottom:1px solid #e5e7eb;">Registros pendientes</td>'
        . '</tr>'
        . $filas
        . '<tr>'
        . '<td style="padding:14px 16px;background:#f3f4f6;font-weight:800;color:#111827;">Total</td>'
        . '<td style="padding:14px 16px;background:#f3f4f6;text-align:right;color:#111827;font-weight:800;">' . $totalPendientes . '</td>'
        . '</tr>'
        . '</table>'
        . '<div style="height:18px;line-height:18px;">&nbsp;</div>'
        . '<p style="margin:0 0 18px;color:#374151;">Se solicita completar en el SGI los registros de horas extras pendientes de definir el motivo.</p>'
        . '<p style="margin:24px 0 0;color:#374151;">Desde ya muchas gracias!<br><strong>SGI (Sistema de Gestión Interna)</strong><br>Metalúrgica RAEI S.A.</p>'
        . '</div></body></html>';
}

/** @param list<array{id:string,nombre:string,pendientes:int}> $areas */
function horas_extra_motivo_mail_texto(string $nombre, array $areas, DateTimeImmutable $desde, DateTimeImmutable $hasta): string
{
    $lineas = [
        'Buen dia' . ($nombre !== '' ? ', ' . $nombre : '') . '.',
        'Hay registros de horas extras pendientes de tipificar en el SGI.',
        'Periodo evaluado: ' . $desde->format('d/m/Y') . ' al ' . $hasta->format('d/m/Y') . '.',
        '',
        'Cargas Pendientes:',
    ];
    foreach ($areas as $area) {
        $lineas[] = '- ' . $area['nombre'] . ': ' . (int) $area['pendientes'];
    }

    return implode("\n", $lineas);
}

/** @return array{ok:bool,error?:string} */
function horas_extra_motivo_send_html_mail(array $mailConfig, string $toMail, string $subject, string $html, string $text): array
{
    $from = (string) $mailConfig['mail_from'];
    $fromName = (string) $mailConfig['mail_from_name'];
    $smtp = $mailConfig['smtp'] ?? [];
    $host = trim((string) ($smtp['host'] ?? ''));
    $port = (int) ($smtp['port'] ?? 587);
    $username = trim((string) ($smtp['username'] ?? ''));
    $password = (string) ($smtp['password'] ?? '');
    $encryption = strtolower(trim((string) ($smtp['encryption'] ?? 'tls')));
    $timeout = max(5, (int) ($smtp['timeout_seconds'] ?? 20));

    if ($host === '' || $username === '' || $password === '') {
        $error = 'Configuracion SMTP incompleta';
        error_log('[horas_extra_motivo] ' . $error);
        return ['ok' => false, 'error' => $error];
    }

    $socket = @stream_socket_client(($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) {
        $error = 'No se pudo conectar al SMTP: ' . $errstr . ' (' . $errno . ')';
        error_log('[horas_extra_motivo] ' . $error);
        return ['ok' => false, 'error' => $error];
    }
    stream_set_timeout($socket, $timeout);

    try {
        password_reset_smtp_expect($socket, [220]);
        password_reset_smtp_command($socket, 'EHLO ' . password_reset_smtp_hostname(), [250]);
        if ($encryption === 'tls') {
            password_reset_smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('No se pudo iniciar TLS con el SMTP');
            }
            password_reset_smtp_command($socket, 'EHLO ' . password_reset_smtp_hostname(), [250]);
        }
        password_reset_smtp_command($socket, 'AUTH LOGIN', [334]);
        password_reset_smtp_command($socket, base64_encode($username), [334]);
        password_reset_smtp_command($socket, base64_encode($password), [235]);
        password_reset_smtp_command($socket, 'MAIL FROM:<' . $from . '>', [250]);
        password_reset_smtp_command($socket, 'RCPT TO:<' . $toMail . '>', [250, 251]);
        password_reset_smtp_command($socket, 'DATA', [354]);
        password_reset_smtp_write($socket, horas_extra_motivo_mime_message($mailConfig, $toMail, $from, $fromName, $subject, $html, $text) . "\r\n.");
        password_reset_smtp_expect($socket, [250]);
        password_reset_smtp_command($socket, 'QUIT', [221]);
        fclose($socket);
        return ['ok' => true];
    } catch (Throwable $e) {
        $error = 'Error SMTP: ' . $e->getMessage();
        error_log('[horas_extra_motivo] ' . $error);
        @fclose($socket);
        return ['ok' => false, 'error' => $error];
    }
}

function horas_extra_motivo_mime_message(array $mailConfig, string $toMail, string $from, string $fromName, string $subject, string $html, string $text): string
{
    $logoPath = trim((string) ($mailConfig['mail_logo_path'] ?? ''));
    $logoBytes = $logoPath !== '' && is_file($logoPath) && is_readable($logoPath) ? file_get_contents($logoPath) : false;
    $relatedBoundary = 'sgi-he-' . bin2hex(random_bytes(8));
    $alternativeBoundary = 'sgi-he-alt-' . bin2hex(random_bytes(8));

    $message = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: multipart/related; boundary="' . $relatedBoundary . '"',
        'From: ' . password_reset_format_mailbox($from, $fromName),
        'Reply-To: ' . $from,
        'To: ' . $toMail,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
    ]) . "\r\n\r\n";
    $message .= '--' . $relatedBoundary . "\r\n";
    $message .= 'Content-Type: multipart/alternative; boundary="' . $alternativeBoundary . '"' . "\r\n\r\n";
    $message .= '--' . $alternativeBoundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= password_reset_dot_stuff($text) . "\r\n\r\n";
    $message .= '--' . $alternativeBoundary . "\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= password_reset_dot_stuff($html) . "\r\n\r\n";
    $message .= '--' . $alternativeBoundary . "--\r\n";

    if ($logoBytes !== false) {
        $message .= '--' . $relatedBoundary . "\r\n";
        $message .= "Content-Type: image/png; name=\"logoRaei.png\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-ID: <logo-raei-reset>\r\n";
        $message .= "Content-Disposition: inline; filename=\"logoRaei.png\"\r\n\r\n";
        $message .= chunk_split(base64_encode($logoBytes), 76, "\r\n") . "\r\n";
    }

    return $message . '--' . $relatedBoundary . '--';
}
