<?php
declare(strict_types=1);

require_once __DIR__ . '/password_reset.php';

const AUTH_SALIDA_DAILY_REPORT_ROLE = 'RRHH_REPORTE_MAIL_AUTH';

function auth_salida_daily_report_config(array $config): array
{
    $pr = password_reset_config($config);
    $logoPath = getenv('SGI_AUTH_SALIDA_REPORT_LOGO_PATH') ?: dirname(__DIR__) . '/assets/logoRAEI.png';

    return [
        'mail_from' => getenv('SGI_AUTH_SALIDA_REPORT_FROM') ?: 'noreply@raei.com.ar',
        'mail_from_name' => getenv('SGI_AUTH_SALIDA_REPORT_FROM_NAME') ?: 'No Reply - Metalúrgica RAEI S.A.',
        'mail_logo_path' => is_file($logoPath) ? $logoPath : (string) ($pr['mail_logo_path'] ?? ''),
        'smtp' => array_merge($pr['smtp'] ?? [], [
            'username' => getenv('SGI_AUTH_SALIDA_REPORT_SMTP_USERNAME') ?: 'noreply@raei.com.ar',
            'password' => getenv('SGI_AUTH_SALIDA_REPORT_SMTP_PASSWORD') ?: (string) (($pr['smtp'] ?? [])['password'] ?? ''),
        ]),
    ];
}

/** @return list<array{mail:string,nombre:string}> */
function auth_salida_daily_report_recipients(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT u.Mail, u.Nombre, u.Apellido
         FROM Usuarios u
         INNER JOIN RolUsuario ru ON ru.UsuariosId = u.Id
         INNER JOIN Roles r ON r.Id = ru.RolesId
         WHERE u.Estado = 1
           AND UPPER(TRIM(r.RolName)) = :rol
           AND u.Mail IS NOT NULL
           AND TRIM(u.Mail) <> ""
         ORDER BY u.Apellido, u.Nombre, u.Mail'
    );
    $stmt->execute(['rol' => AUTH_SALIDA_DAILY_REPORT_ROLE]);

    $recipients = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mail = strtolower(trim((string) $row['Mail']));
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $nombre = trim((string) $row['Nombre'] . ' ' . (string) $row['Apellido']);
        $recipients[$mail] = [
            'mail' => $mail,
            'nombre' => $nombre !== '' ? $nombre : $mail,
        ];
    }

    return array_values($recipients);
}

/** @return list<array<string, string>> */
function auth_salida_daily_report_rows(PDO $pdo, DateTimeImmutable $date): array
{
    $fecha = $date->format('Y-m-d');
    $stmt = $pdo->prepare(
        'SELECT p.Legajo, p.Nombre, p.Apellido,
                a.Fecha, a.HoraSalida, a.FechaRegreso, a.HoraRegreso,
                a.FechaHoraSalidaEfectiva, a.FechaHoraRegresoEfectiva,
                m.MotivoAuth, a.MotivoDetalle
         FROM AuthSalida a
         INNER JOIN Personal p ON p.Id = a.PersonalId
         INNER JOIN AuthSalidaMotivos m ON m.Id = a.MotivoId
         WHERE a.Estado = 1
           AND DATE(a.Fecha) <= :fecha
           AND DATE(a.FechaRegreso) >= :fecha
         ORDER BY a.Fecha, a.HoraSalida, p.Apellido, p.Nombre'
    );
    $stmt->execute(['fecha' => $fecha]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'legajo' => (string) $row['Legajo'],
            'empleado' => trim((string) $row['Apellido'] . ', ' . (string) $row['Nombre']),
            'fechaSalida' => auth_salida_daily_report_format_date((string) $row['Fecha']),
            'horaSalida' => auth_salida_daily_report_format_time((string) $row['HoraSalida']),
            'fechaRegreso' => auth_salida_daily_report_format_date((string) $row['FechaRegreso']),
            'horaRegreso' => auth_salida_daily_report_format_time((string) $row['HoraRegreso']),
            'motivo' => (string) $row['MotivoAuth'],
            'detalle' => trim((string) ($row['MotivoDetalle'] ?? '')),
            'salidaEfectiva' => auth_salida_daily_report_format_datetime((string) ($row['FechaHoraSalidaEfectiva'] ?? '')),
            'regresoEfectivo' => auth_salida_daily_report_format_datetime((string) ($row['FechaHoraRegresoEfectiva'] ?? '')),
        ];
    }

    return $rows;
}

function auth_salida_daily_report_subject(DateTimeImmutable $date): string
{
    return 'Autorizaciones de Salida - ' . $date->format('d/m/Y');
}

/** @param list<array<string, string>> $rows */
function auth_salida_daily_report_text_body(array $rows): string
{
    $body = "Buenos Días!\n\n"
        . "Se pone en conocimiento de las Autorizaciones de Salida correspondientes al día de la fecha:\n\n";

    if ($rows === []) {
        $body .= "No se registran autorizaciones de salida para la fecha.\n\n";
    } else {
        foreach ($rows as $row) {
            $body .= sprintf(
                "- %s | %s | %s %s a %s %s | %s%s | Salida efectiva: %s | Regreso efectivo: %s\n",
                $row['legajo'],
                $row['empleado'],
                $row['fechaSalida'],
                $row['horaSalida'],
                $row['fechaRegreso'],
                $row['horaRegreso'],
                $row['motivo'],
                $row['detalle'] !== '' ? ' - ' . $row['detalle'] : '',
                $row['salidaEfectiva'] !== '' ? $row['salidaEfectiva'] : 'No registrada',
                $row['regresoEfectivo'] !== '' ? $row['regresoEfectivo'] : 'No registrado'
            );
        }
        $body .= "\n\n";
    }

    return $body
        . "Que tengas muy buen día\n\n"
        . "Cordialmente,\n\n"
        . "<strong>El Equipo de Desarrollo SGI\n"
        . "Metalúrgica RAEI S.A.</strong>";
}

/** @param list<array<string, string>> $rows */
function auth_salida_daily_report_html_body(array $rows, ?string $logoCid): string
{
    $logo = $logoCid !== null
        ? '<div style="margin:0 0 24px;"><img src="cid:' . htmlspecialchars($logoCid, ENT_QUOTES, 'UTF-8') . '" alt="RAEI" style="display:inline-block;max-width:140px;height:auto;border:0;" /></div>'
        : '';

    $tableRows = '';
    if ($rows === []) {
        $tableRows = '<tr><td colspan="10" style="padding:10px;border:1px solid #d0d7de;text-align:center;">No se registran autorizaciones de salida para la fecha.</td></tr>';
    } else {
        foreach ($rows as $row) {
            $tableRows .= '<tr>'
                . auth_salida_daily_report_td($row['legajo'])
                . auth_salida_daily_report_td($row['empleado'])
                . auth_salida_daily_report_td($row['fechaSalida'])
                . auth_salida_daily_report_td($row['horaSalida'])
                . auth_salida_daily_report_td($row['fechaRegreso'])
                . auth_salida_daily_report_td($row['horaRegreso'])
                . auth_salida_daily_report_td($row['motivo'])
                . auth_salida_daily_report_td($row['detalle'] !== '' ? $row['detalle'] : '-')
                . auth_salida_daily_report_td($row['salidaEfectiva'] !== '' ? $row['salidaEfectiva'] : 'No registrada')
                . auth_salida_daily_report_td($row['regresoEfectivo'] !== '' ? $row['regresoEfectivo'] : 'No registrado')
                . '</tr>';
        }
    }

    return '<!DOCTYPE html><html lang="es"><body style="margin:0;padding:24px;background:#ffffff;color:#202124;font-family:Segoe UI,Arial,sans-serif;font-size:14px;line-height:1.5;">'
        . '<div style="max-width:980px;margin:0 auto;">'
        . $logo
        . '<p style="margin:0 0 16px;">Buenos Días!</p>'
        . '<p style="margin:0 0 18px;">Se pone en conocimiento de las Autorizaciones de Salida correspondientes al día de la fecha:</p>'
        . '<table role="presentation" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;font-size:13px;margin:0 0 36px;">'
        . '<thead><tr>'
        . auth_salida_daily_report_th('Legajo')
        . auth_salida_daily_report_th('Empleado')
        . auth_salida_daily_report_th('Fecha Salida')
        . auth_salida_daily_report_th('Hora Salida')
        . auth_salida_daily_report_th('Fecha Regreso')
        . auth_salida_daily_report_th('Hora Regreso')
        . auth_salida_daily_report_th('Motivo')
        . auth_salida_daily_report_th('Detalle')
        . auth_salida_daily_report_th('Salida Efectiva')
        . auth_salida_daily_report_th('Regreso Efectivo')
        . '</tr></thead><tbody>'
        . $tableRows
        . '</tbody></table>'
        . '<p style="margin:0 0 16px;"><strong>Que tengas muy buen día</strong></p>'
        . '<p style="margin:0;"><strong>Cordialmente,<br><br>El Equipo de Desarrollo SGI<br>Metalúrgica RAEI S.A.</strong></p>'
        . '</div></body></html>';
}

function auth_salida_daily_report_send(PDO $pdo, array $config, DateTimeImmutable $date): array
{
    $reportConfig = auth_salida_daily_report_config($config);
    $recipients = auth_salida_daily_report_recipients($pdo);
    $rows = auth_salida_daily_report_rows($pdo, $date);

    if ($recipients === []) {
        return [
            'ok' => true,
            'sent' => 0,
            'recipients' => [],
            'rows' => count($rows),
            'message' => 'No hay usuarios activos con rol ' . AUTH_SALIDA_DAILY_REPORT_ROLE,
        ];
    }

    $subject = auth_salida_daily_report_subject($date);
    $textBody = auth_salida_daily_report_text_body($rows);
    $logoPath = trim((string) ($reportConfig['mail_logo_path'] ?? ''));
    $htmlBody = auth_salida_daily_report_html_body(
        $rows,
        $logoPath !== '' && is_file($logoPath) && is_readable($logoPath) ? 'logo-raei-auth-salida' : null
    );
    $sent = 0;
    $failed = [];

    foreach ($recipients as $recipient) {
        $ok = auth_salida_daily_report_send_smtp(
            $reportConfig,
            $recipient['mail'],
            (string) $reportConfig['mail_from'],
            (string) $reportConfig['mail_from_name'],
            $subject,
            $textBody,
            $htmlBody
        );
        if ($ok) {
            $sent++;
        } else {
            $failed[] = $recipient['mail'];
        }
    }

    return [
        'ok' => $failed === [],
        'sent' => $sent,
        'recipients' => array_column($recipients, 'mail'),
        'rows' => count($rows),
        'failed' => $failed,
    ];
}

function auth_salida_daily_report_send_smtp(
    array $reportConfig,
    string $toMail,
    string $from,
    string $fromName,
    string $subject,
    string $textBody,
    string $htmlBody
): bool {
    $smtp = $reportConfig['smtp'] ?? [];
    $host = trim((string) ($smtp['host'] ?? ''));
    $port = (int) ($smtp['port'] ?? 587);
    $username = trim((string) ($smtp['username'] ?? ''));
    $password = (string) ($smtp['password'] ?? '');
    $ssl = filter_var($smtp['ssl'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $encryption = strtolower(trim((string) ($smtp['encryption'] ?? ($ssl ? 'tls' : ''))));
    $timeout = max(5, (int) ($smtp['timeout_seconds'] ?? 20));

    if ($host === '' || $username === '' || $password === '') {
        error_log('[auth_salida_daily_report] Configuracion SMTP incompleta');
        return false;
    }

    $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
    $socket = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT
    );

    if (!is_resource($socket)) {
        error_log('[auth_salida_daily_report] No se pudo conectar al SMTP: ' . $errstr . ' (' . $errno . ')');
        return false;
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
        password_reset_smtp_write($socket, auth_salida_daily_report_mime_message($reportConfig, $toMail, $from, $fromName, $subject, $textBody, $htmlBody) . "\r\n.");
        password_reset_smtp_expect($socket, [250]);
        password_reset_smtp_command($socket, 'QUIT', [221]);

        fclose($socket);
        return true;
    } catch (Throwable $e) {
        error_log('[auth_salida_daily_report] Error SMTP: ' . $e->getMessage());
        @fclose($socket);
        return false;
    }
}

function auth_salida_daily_report_mime_message(
    array $reportConfig,
    string $toMail,
    string $from,
    string $fromName,
    string $subject,
    string $textBody,
    string $htmlBody
): string {
    $logoPath = trim((string) ($reportConfig['mail_logo_path'] ?? ''));
    $logoBytes = $logoPath !== '' && is_file($logoPath) && is_readable($logoPath)
        ? file_get_contents($logoPath)
        : false;
    $logoCid = 'logo-raei-auth-salida';
    $relatedBoundary = 'sgi-auth-salida-' . bin2hex(random_bytes(8));
    $alternativeBoundary = 'sgi-auth-salida-alt-' . bin2hex(random_bytes(8));

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: multipart/related; boundary="' . $relatedBoundary . '"',
        'From: ' . password_reset_format_mailbox($from, $fromName),
        'Reply-To: ' . $from,
        'To: ' . $toMail,
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
    ];

    $message = implode("\r\n", $headers) . "\r\n\r\n";
    $message .= '--' . $relatedBoundary . "\r\n";
    $message .= 'Content-Type: multipart/alternative; boundary="' . $alternativeBoundary . '"' . "\r\n\r\n";
    $message .= '--' . $alternativeBoundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= password_reset_dot_stuff($textBody) . "\r\n\r\n";
    $message .= '--' . $alternativeBoundary . "\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= password_reset_dot_stuff($htmlBody) . "\r\n\r\n";
    $message .= '--' . $alternativeBoundary . "--\r\n";

    if ($logoBytes !== false) {
        $message .= '--' . $relatedBoundary . "\r\n";
        $message .= "Content-Type: image/png; name=\"logoRaei.png\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-ID: <{$logoCid}>\r\n";
        $message .= "Content-Disposition: inline; filename=\"logoRaei.png\"\r\n\r\n";
        $message .= chunk_split(base64_encode($logoBytes), 76, "\r\n") . "\r\n";
    }

    $message .= '--' . $relatedBoundary . '--';

    return $message;
}

function auth_salida_daily_report_th(string $text): string
{
    return '<th style="padding:8px;border:1px solid #d0d7de;background:#f6f8fa;text-align:left;font-weight:600;">'
        . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
        . '</th>';
}

function auth_salida_daily_report_td(string $text): string
{
    return '<td style="padding:8px;border:1px solid #d0d7de;vertical-align:top;">'
        . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
        . '</td>';
}

function auth_salida_daily_report_format_date(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m-d', substr($value, 0, 10));
    return $date ? $date->format('d/m/Y') : $value;
}

function auth_salida_daily_report_format_time(string $value): string
{
    return substr($value, 0, 5);
}

function auth_salida_daily_report_format_datetime(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format('d/m/Y H:i');
    } catch (Throwable) {
        return $value;
    }
}
