<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const NT_STATUS_GENERADA = 1;
const NT_STATUS_RECEPCION_DESPACHO = 2;
const NT_STATUS_EN_TRANSITO = 3;
const NT_STATUS_RECEPCION_SEDE = 4;
const NT_STATUS_ENTREGADO = 5;
const NT_STATUS_RECIBIDA = 6;
const NT_STATUS_ANULADA = 7;

function notas_traslado_has_role(array $roles, string $roleName): bool
{
    if (function_exists('user_has_role_name')) {
        return user_has_role_name($roles, $roleName);
    }

    $expected = strtoupper(trim($roleName));
    foreach ($roles as $role) {
        if (strtoupper(trim((string) $role)) === $expected) {
            return true;
        }
    }
    return false;
}

function notas_traslado_require_role(array $roles, array $config): void
{
    require_any_role($roles, $config);
    $allowed = [
        'SYSADMIN',
        'ADMIN',
        'NOTA_TRASLADO',
        'NOTA_TRASLADO_FULL_USERS',
        'NOTA_TRASLADO_DESPACHO',
        'NOTA_TRASLADO_TRANSPORTE',
    ];
    $upper = array_map(static fn ($r) => strtoupper(trim((string) $r)), $roles);
    if (array_intersect($allowed, $upper) === []) {
        json_response(['message' => 'Sin permisos para Notas de Traslado'], 403, $config);
        exit;
    }
}

function notas_traslado_require_auth(PDO $pdo, array $config): array
{
    if (function_exists('require_auth_or_web_bridge')) {
        return require_auth_or_web_bridge($pdo, $config);
    }

    return require_auth($pdo, $config);
}

function notas_traslado_is_admin(array $roles): bool
{
    return notas_traslado_has_role($roles, 'SYSADMIN') || notas_traslado_has_role($roles, 'ADMIN');
}

function notas_traslado_extract_id(mixed $raw): string
{
    $value = trim((string) $raw);
    if ($value === '') {
        return '';
    }

    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)) {
        return strtolower($value);
    }

    $query = parse_url($value, PHP_URL_QUERY);
    if (is_string($query) && $query !== '') {
        parse_str($query, $params);
        foreach (['key', 'id', 'notaTrasladoId'] as $param) {
            if (isset($params[$param]) && is_string($params[$param])) {
                return notas_traslado_extract_id($params[$param]);
            }
        }
    }

    if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value, $m)) {
        return strtolower($m[0]);
    }

    return '';
}

function notas_traslado_status_label(int $status): string
{
    return match ($status) {
        NT_STATUS_GENERADA => 'Generada',
        NT_STATUS_RECEPCION_DESPACHO => 'Recepcion Despacho',
        NT_STATUS_EN_TRANSITO => 'En transito',
        NT_STATUS_RECEPCION_SEDE => 'Recepcion Sede',
        NT_STATUS_ENTREGADO => 'Entregado',
        NT_STATUS_RECIBIDA => 'Recibida',
        NT_STATUS_ANULADA => 'Anulada',
        default => 'Desconocido',
    };
}

function notas_traslado_pdf_escape(string $text): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if (is_string($converted)) {
            $text = $converted;
        }
    }

    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function notas_traslado_pdf_wrap(string $text, int $maxChars = 86): array
{
    $words = preg_split('/\s+/u', trim($text)) ?: [];
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        $candidate = $current === '' ? $word : $current . ' ' . $word;
        $candidateLength = function_exists('mb_strlen') ? mb_strlen($candidate) : strlen($candidate);
        if ($candidateLength > $maxChars && $current !== '') {
            $lines[] = $current;
            $current = $word;
            continue;
        }
        $current = $candidate;
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return $lines !== [] ? $lines : ['-'];
}

function notas_traslado_pdf_add_line(array &$pages, string &$content, float &$y, string $text, int $size = 10, bool $bold = false, float $x = 40): void
{
    if ($y < 58) {
        $pages[] = $content;
        $content = '';
        $y = 802;
    }

    $font = $bold ? 'F2' : 'F1';
    $content .= sprintf("BT /%s %d Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n", $font, $size, $x, $y, notas_traslado_pdf_escape($text));
    $y -= max(12, $size + 4);
}

function notas_traslado_pdf_text(string &$content, float $x, float $y, string $text, int $size = 10, bool $bold = false, string $rgb = '0 0 0'): void
{
    $font = $bold ? 'F2' : 'F1';
    $content .= sprintf(
        "%s rg BT /%s %d Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n0 0 0 rg\n",
        $rgb,
        $font,
        $size,
        $x,
        $y,
        notas_traslado_pdf_escape($text)
    );
}

function notas_traslado_pdf_rect(string &$content, float $x, float $y, float $w, float $h, string $stroke = '0.82 0.84 0.86', string $fill = ''): void
{
    if ($fill !== '') {
        $content .= sprintf("%s rg %.2F %.2F %.2F %.2F re f\n0 0 0 rg\n", $fill, $x, $y, $w, $h);
    }

    $content .= sprintf("%s RG %.2F w %.2F %.2F %.2F %.2F re S\n0 0 0 RG\n", $stroke, 0.8, $x, $y, $w, $h);
}

function notas_traslado_pdf_line(string &$content, float $x1, float $y1, float $x2, float $y2, string $stroke = '0.82 0.84 0.86'): void
{
    $content .= sprintf("%s RG %.2F w %.2F %.2F m %.2F %.2F l S\n0 0 0 RG\n", $stroke, 0.8, $x1, $y1, $x2, $y2);
}

function notas_traslado_pdf_summary_box(string &$content, float $x, float $y, float $w, string $title, string $value): void
{
    notas_traslado_pdf_rect($content, $x, $y, $w, 44);
    notas_traslado_pdf_text($content, $x + 10, $y + 28, $title, 9, false, '0.35 0.37 0.40');
    notas_traslado_pdf_text($content, $x + 10, $y + 12, $value !== '' ? $value : '-', 12, true);
}

function notas_traslado_pdf_build(array $nota, array $detalles): string
{
    $pages = [];
    $content = '';
    $y = 802.0;

    notas_traslado_pdf_text($content, 40, 760, 'RAEI', 26, true, '0.05 0.24 0.52');
    notas_traslado_pdf_text($content, 40, 730, 'METALURGICA RAEI S.A.', 11, true);
    notas_traslado_pdf_text($content, 40, 716, 'CUIT: 30-51663575-0');
    notas_traslado_pdf_text($content, 40, 702, 'IVA RESPONSABLE INSCRIPTO');

    notas_traslado_pdf_rect($content, 238, 682, 115, 115, '0.82 0.84 0.86', '1 1 1');
    notas_traslado_pdf_text($content, 268, 755, 'QR', 20, true, '0.05 0.24 0.52');
    notas_traslado_pdf_text($content, 255, 735, 'Nota de Traslado', 8, true);
    notas_traslado_pdf_text($content, 249, 720, substr((string) $nota['Id'], 0, 18), 7);
    notas_traslado_pdf_text($content, 249, 708, substr((string) $nota['Id'], 18), 7);

    notas_traslado_pdf_text($content, 380, 760, 'NOTA DE TRASLADO', 20, true, '0.05 0.24 0.52');
    notas_traslado_pdf_text($content, 430, 738, 'Nro. ' . (string) $nota['NumeroNota'], 13, true, '0.05 0.24 0.52');
    notas_traslado_pdf_text($content, 398, 722, 'Documento no valido como factura', 9, false, '0.35 0.37 0.40');
    notas_traslado_pdf_text($content, 450, 696, 'Fecha: ' . date('d/m/Y', strtotime((string) $nota['Fecha'])), 10, true);

    notas_traslado_pdf_line($content, 32, 668, 563, 668);

    $destino = (bool) $nota['Externo']
        ? ((string) ($nota['EmpresaNombre'] ?? $nota['ReceptorExterno'] ?? 'Externo'))
        : ((string) ($nota['Destino'] ?? '-'));

    notas_traslado_pdf_summary_box($content, 40, 608, 250, 'Origen', (string) ($nota['Origen'] ?? '-'));
    notas_traslado_pdf_summary_box($content, 305, 608, 250, 'Destino', $destino);
    notas_traslado_pdf_summary_box($content, 40, 552, 250, 'Envia', (string) ($nota['NombreEnvia'] ?? '-'));
    notas_traslado_pdf_summary_box($content, 305, 552, 250, 'Recibe', (string) ($nota['NombreRecibe'] ?? '-'));

    $y = 520.0;
    notas_traslado_pdf_rect($content, 40, $y - 24, 515, 24, '0.05 0.24 0.52', '0.05 0.24 0.52');
    notas_traslado_pdf_text($content, 70, $y - 16, 'Cantidad', 10, true, '1 1 1');
    notas_traslado_pdf_text($content, 150, $y - 16, 'Detalle', 10, true, '1 1 1');
    $y -= 24;

    if ($detalles === []) {
        notas_traslado_pdf_text($content, 150, $y - 16, 'Sin items cargados', 10);
        notas_traslado_pdf_line($content, 40, $y - 24, 555, $y - 24);
        $y -= 24;
    }

    foreach ($detalles as $detalle) {
        if ($y < 105) {
            $pages[] = $content;
            $content = '';
            $y = 802.0;
        }

        $rowTop = $y;
        notas_traslado_pdf_text($content, 80, $y - 16, (string) ((int) $detalle['Cantidad']), 10);
        $wrapped = notas_traslado_pdf_wrap((string) $detalle['Detalle']);
        foreach ($wrapped as $index => $line) {
            notas_traslado_pdf_text($content, 150, $y - 16, $line, 10);
            $y -= 14;
        }

        $rowHeight = max(24, $rowTop - $y + 8);
        notas_traslado_pdf_line($content, 40, $rowTop - $rowHeight, 555, $rowTop - $rowHeight);
        $y = $rowTop - $rowHeight;
    }

    if ((bool) $nota['Externo']) {
        $y -= 18;
        notas_traslado_pdf_rect($content, 40, $y - 118, 515, 112);
        notas_traslado_pdf_text($content, 52, $y - 24, 'Recepcion externa', 10, true, '0.35 0.37 0.40');
        notas_traslado_pdf_line($content, 70, $y - 88, 200, $y - 88, '0.25 0.25 0.25');
        notas_traslado_pdf_line($content, 235, $y - 88, 365, $y - 88, '0.25 0.25 0.25');
        notas_traslado_pdf_line($content, 400, $y - 88, 530, $y - 88, '0.25 0.25 0.25');
        notas_traslado_pdf_text($content, 92, $y - 104, 'Fecha de Recepcion', 8, false, '0.35 0.37 0.40');
        notas_traslado_pdf_text($content, 265, $y - 104, 'Firma del Receptor', 8, false, '0.35 0.37 0.40');
        notas_traslado_pdf_text($content, 446, $y - 104, 'Aclaracion', 8, false, '0.35 0.37 0.40');
    }

    notas_traslado_pdf_line($content, 32, 58, 563, 58, '0.88 0.89 0.90');
    notas_traslado_pdf_text($content, 52, 34, 'METALURGICA RAEI S.A. - 25 de Mayo 2765, San Fernando CP (1646) - Buenos Aires, Argentina', 10, true);
    $pages[] = $content;

    $pageCount = count($pages);
    $fontRegularId = 3 + ($pageCount * 2);
    $fontBoldId = $fontRegularId + 1;
    $objects = [];
    $kids = [];

    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    foreach ($pages as $index => $stream) {
        $pageObjId = 3 + ($index * 2);
        $contentObjId = $pageObjId + 1;
        $kids[] = $pageObjId . ' 0 R';
        $objects[$pageObjId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $fontRegularId . ' 0 R /F2 ' . $fontBoldId . ' 0 R >> >> /Contents ' . $contentObjId . ' 0 R >>';
        $objects[$contentObjId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }

    $objects[2] = '<< /Type /Pages /Count ' . $pageCount . ' /Kids [' . implode(' ', $kids) . '] >>';
    $objects[$fontRegularId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[$fontBoldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $max = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($max + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $max; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
    }
    $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

    return $pdf;
}

function notas_traslado_map_row(array $row): array
{
    $statusId = (int) $row['StatusNotaTrasladoId'];
    $fechaActualizacion = strtotime((string) ($row['FechaActualizacion'] ?? $row['Fecha']));
    return [
        'id' => (string) $row['Id'],
        'numeroNota' => (string) $row['NumeroNota'],
        'fecha' => substr((string) $row['Fecha'], 0, 10),
        'origen' => (string) ($row['Origen'] ?? '-'),
        'destino' => (string) ($row['Destino'] ?? '-'),
        'externo' => (bool) $row['Externo'],
        'destinoId' => $row['DestinoId'] !== null ? (string) $row['DestinoId'] : null,
        'userAdd' => (string) $row['UserAdd'],
        'userRecibeId' => $row['UserRecibeId'] !== null ? (string) $row['UserRecibeId'] : null,
        'documentoFirmado' => $row['DocumentoFirmado'] !== null ? (string) $row['DocumentoFirmado'] : null,
        'nombreEnvia' => (string) $row['NombreEnvia'],
        'nombreRecibe' => (string) $row['NombreRecibe'],
        'statusId' => $statusId,
        'status' => notas_traslado_status_label($statusId),
        'fechaActualizacion' => $fechaActualizacion !== false ? date(DATE_ATOM, $fechaActualizacion) : substr((string) $row['Fecha'], 0, 10),
        'usuarioActualizacion' => trim((string) ($row['UsuarioActualizacion'] ?? '')),
        'transportistaAsignadoId' => $row['TransportistaAsignadoId'] !== null ? (string) $row['TransportistaAsignadoId'] : null,
        'fechaAsignacionTransporte' => $row['FechaAsignacionTransporte'] !== null ? (string) $row['FechaAsignacionTransporte'] : null,
    ];
}

function notas_traslado_registrar_traza(PDO $pdo, string $notaId, string $accion, ?int $statusId, string $detalle, string $usuarioId): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO NotasTrasladoTraza (NotaTrasladoId, FechaHora, UsuarioId, StatusNotaTrasladoId, Accion, Detalle)
         VALUES (:notaId, NOW(), :usuarioId, :statusId, :accion, :detalle)'
    );
    $stmt->execute([
        'notaId' => $notaId,
        'usuarioId' => $usuarioId,
        'statusId' => $statusId,
        'accion' => $accion,
        'detalle' => $detalle,
    ]);
}

function notas_traslado_registrar_log(PDO $pdo, string $usuarioId, string $detalle): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO Logs (UsuarioId, IP, Accion, Detalle, Plataforma, DateAction)
         VALUES (:usuarioId, :ip, :accion, :detalle, :plataforma, NOW())'
    );
    $stmt->execute([
        'usuarioId' => $usuarioId,
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        'accion' => 'NOTA TRASLADO',
        'detalle' => $detalle,
        'plataforma' => 'mobil',
    ]);
}

function notas_traslado_actualizar_estado_y_traza(
    PDO $pdo,
    string $notaId,
    int $statusId,
    string $usuarioId,
    string $accion,
    string $detalle,
    array $extraSet = [],
    ?string $logDetalle = null
): void {
    $set = [
        'StatusNotaTrasladoId = :status',
        'UserMod = :usuarioId',
        'DateMod = NOW()',
    ];
    $params = [
        'status' => $statusId,
        'usuarioId' => $usuarioId,
        'id' => $notaId,
    ];

    foreach ($extraSet as $column => $value) {
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $column)) {
            throw new InvalidArgumentException('Columna no valida para actualizacion de Nota de Traslado.');
        }

        if ($value === '__NOW__') {
            $set[] = $column . ' = NOW()';
            continue;
        }

        $param = 'extra_' . $column;
        $set[] = $column . ' = :' . $param;
        $params[$param] = $value;
    }

    $upd = $pdo->prepare(
        'UPDATE NotasTraslado
         SET ' . implode(', ', $set) . '
         WHERE Id = :id AND Estado = 1'
    );
    $upd->execute($params);

    if ($upd->rowCount() < 1) {
        throw new RuntimeException('No se pudo actualizar el estado de la Nota de Traslado.');
    }

    notas_traslado_registrar_traza($pdo, $notaId, $accion, $statusId, $detalle, $usuarioId);
    if ($logDetalle !== null && trim($logDetalle) !== '') {
        notas_traslado_registrar_log($pdo, $usuarioId, $logDetalle);
    }
}

function notas_traslado_base_select(): string
{
    return 'SELECT n.Id, n.NumeroNota, n.Fecha, n.OrigenId, n.DestinoId, n.Externo, n.NombreEnvia, n.NombreRecibe, n.UserRecibeId,
                   n.UserAdd, n.ReceptorExterno, n.DocumentoFirmado, COALESCE(n.DateMod, n.DateAdd) AS FechaActualizacion,
                   CONCAT(COALESCE(um.Nombre, ua.Nombre), " ", COALESCE(um.Apellido, ua.Apellido)) AS UsuarioActualizacion,
                   e.Nombre AS EmpresaNombre,
                   ua.DepositoId AS UserAddDepositoId,
                   n.StatusNotaTrasladoId, n.TransportistaAsignadoId, n.FechaAsignacionTransporte,
                   o.Nombre AS Origen,
                   CASE WHEN n.Externo = 1 THEN COALESCE(e.Nombre, n.ReceptorExterno, "Externo")
                        ELSE COALESCE(d.Nombre, "-") END AS Destino
            FROM NotasTraslado n
            INNER JOIN Usuarios ua ON ua.Id = n.UserAdd
            LEFT JOIN Usuarios um ON um.Id = n.UserMod
            LEFT JOIN Depositos o ON o.Id = n.OrigenId
            LEFT JOIN Depositos d ON d.Id = n.DestinoId
            LEFT JOIN Empresa e ON e.Id = n.EmpresaId';
}

function notas_traslado_puede_ver(array $nota, array $roles, string $usuarioId, string $depositoId): bool
{
    if (notas_traslado_is_admin($roles) || notas_traslado_has_role($roles, 'NOTA_TRASLADO_FULL_USERS')) {
        return true;
    }

    if (strtolower((string) $nota['UserAdd']) === strtolower($usuarioId)) {
        return true;
    }

    $status = (int) $nota['StatusNotaTrasladoId'];
    if (
        notas_traslado_has_role($roles, 'NOTA_TRASLADO_TRANSPORTE')
        && $status !== NT_STATUS_RECEPCION_SEDE
        && strtolower((string) ($nota['TransportistaAsignadoId'] ?? '')) === strtolower($usuarioId)
    ) {
        return true;
    }

    if (!notas_traslado_has_role($roles, 'NOTA_TRASLADO_DESPACHO') || $depositoId === '') {
        return false;
    }

    $mismaSucursalOrigen = strtolower((string) $nota['UserAddDepositoId']) === strtolower($depositoId);
    $mismaSucursalDestino = strtolower((string) $nota['DestinoId']) === strtolower($depositoId);

    return (
        $mismaSucursalOrigen
        && in_array($status, [NT_STATUS_RECEPCION_DESPACHO, NT_STATUS_ENTREGADO], true)
    ) || (
        $mismaSucursalDestino
        && $status === NT_STATUS_EN_TRANSITO
    );
}

function handle_notas_traslado_list(PDO $pdo, array $config): void
{
    $auth = notas_traslado_require_auth($pdo, $config);
    notas_traslado_require_role($auth['roles'], $config);

    $desde = trim((string) ($_GET['desde'] ?? ''));
    $hasta = trim((string) ($_GET['hasta'] ?? ''));
    $mostrarTodos = filter_var($_GET['mostrarTodos'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($desde !== '' || $hasta !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            json_response(['message' => 'Parámetros desde/hasta inválidos (YYYY-MM-DD)'], 400, $config);
            return;
        }

        if ($desde > $hasta) {
            json_response(['message' => 'La fecha desde no puede ser posterior a hasta'], 400, $config);
            return;
        }
    }

    $usuarioId = (string) $auth['user']['Id'];
    $depositoId = (string) ($auth['user']['DepositoId'] ?? '');
    $roles = $auth['roles'];
    $isAdmin = notas_traslado_is_admin($roles) || notas_traslado_has_role($roles, 'NOTA_TRASLADO_FULL_USERS');
    $isDespacho = notas_traslado_has_role($roles, 'NOTA_TRASLADO_DESPACHO');
    $isTransporte = notas_traslado_has_role($roles, 'NOTA_TRASLADO_TRANSPORTE');

    $where = ['n.Estado = 1'];
    $params = [];

    if ($desde !== '' && $hasta !== '') {
        $where[] = 'DATE(n.Fecha) BETWEEN :desde AND :hasta';
        $params['desde'] = $desde;
        $params['hasta'] = $hasta;
    }

    if (!$isAdmin && !$mostrarTodos) {
        $visibility = ['n.UserAdd = :usuarioId'];
        $params['usuarioId'] = $usuarioId;

        if ($isTransporte) {
            $visibility[] = '(n.TransportistaAsignadoId = :transportistaId AND n.StatusNotaTrasladoId <> ' . NT_STATUS_RECEPCION_SEDE . ')';
            $params['transportistaId'] = $usuarioId;
        }

        if ($isDespacho && $depositoId !== '') {
            $visibility[] = '(ua.DepositoId = :depositoId AND n.StatusNotaTrasladoId IN (' . NT_STATUS_RECEPCION_DESPACHO . ',' . NT_STATUS_ENTREGADO . '))';
            $visibility[] = '(n.DestinoId = :depositoId AND n.StatusNotaTrasladoId = ' . NT_STATUS_EN_TRANSITO . ')';
            $params['depositoId'] = $depositoId;
        }

        $where[] = '(' . implode(' OR ', $visibility) . ')';
    }

    $sql = notas_traslado_base_select() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY n.DateAdd DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    json_response(['notas' => array_map('notas_traslado_map_row', $rows)], 200, $config);
}

function handle_notas_traslado_scan(PDO $pdo, array $config): void
{
    $auth = notas_traslado_require_auth($pdo, $config);
    notas_traslado_require_role($auth['roles'], $config);

    $body = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
    $notaId = notas_traslado_extract_id($body['notaTrasladoId'] ?? $body['id'] ?? $body['key'] ?? $body['qr'] ?? '');
    if ($notaId === '') {
        json_response(['ok' => false, 'message' => 'Nota de Traslado no válida'], 400, $config);
        return;
    }

    $usuarioId = (string) $auth['user']['Id'];
    $depositoId = (string) ($auth['user']['DepositoId'] ?? '');
    $roles = $auth['roles'];
    $isTransporte = notas_traslado_has_role($roles, 'NOTA_TRASLADO_TRANSPORTE');
    $isDespacho = notas_traslado_has_role($roles, 'NOTA_TRASLADO_DESPACHO') || notas_traslado_is_admin($roles);
    $personalId = notas_traslado_personal_id_usuario($pdo, $auth['user']);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(notas_traslado_base_select() . ' WHERE n.Id = :id AND n.Estado = 1 LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $notaId]);
        $nota = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$nota) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => 'Nota de Traslado no encontrada'], 404, $config);
            return;
        }

        $status = (int) $nota['StatusNotaTrasladoId'];
        $isAdmin = notas_traslado_is_admin($roles);
        $mismaSucursalOrigen = $depositoId !== ''
            && strtolower((string) $nota['UserAddDepositoId']) === strtolower($depositoId);

        if ($isDespacho && $status === NT_STATUS_GENERADA && ($isAdmin || $mismaSucursalOrigen)) {
            notas_traslado_actualizar_estado_y_traza(
                $pdo,
                $notaId,
                NT_STATUS_RECEPCION_DESPACHO,
                $usuarioId,
                'RECEPCION_DESPACHO_QR',
                'Despacho recibio los items a trasladar.',
                [],
                'Recepcion Despacho Nota de Traslado: ' . (string) $nota['NumeroNota']
            );
            $pdo->commit();
            json_response(['ok' => true, 'accion' => 'recepcion_despacho', 'message' => 'Recepción en despacho registrada correctamente.'], 200, $config);
            return;
        }

        if ($isTransporte && ($status === NT_STATUS_RECEPCION_DESPACHO || $status === NT_STATUS_EN_TRANSITO)) {
            if (strtolower((string) ($nota['TransportistaAsignadoId'] ?? '')) === strtolower($usuarioId)) {
                $pdo->rollBack();
                json_response([
                    'ok' => false,
                    'message' => 'La Nota de Traslado que intentas asignarte, ya está asignada a tu nombre.',
                ], 409, $config);
                return;
            }

            notas_traslado_actualizar_estado_y_traza(
                $pdo,
                $notaId,
                NT_STATUS_EN_TRANSITO,
                $usuarioId,
                'ASIGNACION_TRANSPORTE',
                'Traslado asignado al transportista por escaneo QR.',
                [
                    'TransportistaAsignadoId' => $usuarioId,
                    'FechaAsignacionTransporte' => '__NOW__',
                ],
                'Cambio estado Nota de Traslado: ' . (string) $nota['NumeroNota'] . ' a ' . notas_traslado_status_label(NT_STATUS_EN_TRANSITO)
            );
            $pdo->commit();
            json_response(['ok' => true, 'accion' => 'asignacion_transporte', 'message' => 'Traslado asignado correctamente.'], 200, $config);
            return;
        }

        $mismaSucursalDestino = $depositoId !== ''
            && strtolower((string) $nota['DestinoId']) === strtolower($depositoId);

        if ($isDespacho && !$nota['Externo'] && $status === NT_STATUS_EN_TRANSITO && ($isAdmin || $mismaSucursalDestino)) {
            notas_traslado_actualizar_estado_y_traza(
                $pdo,
                $notaId,
                NT_STATUS_RECEPCION_SEDE,
                $usuarioId,
                'RECEPCION_SEDE',
                'La sede destino recibio el traslado.',
                [],
                'Cambio estado Nota de Traslado: ' . (string) $nota['NumeroNota'] . ' a ' . notas_traslado_status_label(NT_STATUS_RECEPCION_SEDE)
            );
            $pdo->commit();
            json_response(['ok' => true, 'accion' => 'recepcion_sede', 'message' => 'Recepción en sede registrada correctamente.'], 200, $config);
            return;
        }

        if (!$nota['Externo'] && $status === NT_STATUS_RECEPCION_SEDE && $personalId !== '' && strtolower((string) $nota['UserRecibeId']) === strtolower($personalId)) {
            notas_traslado_actualizar_estado_y_traza(
                $pdo,
                $notaId,
                NT_STATUS_RECIBIDA,
                $usuarioId,
                'RECEPCION_FINAL_QR',
                'La nota fue cerrada como recibida.',
                [],
                'Recepcion QR Nota de Traslado: ' . (string) $nota['NumeroNota']
            );
            $pdo->commit();
            json_response(['ok' => true, 'accion' => 'recepcion_final', 'message' => 'Traslado recibido correctamente.'], 200, $config);
            return;
        }

        $pdo->rollBack();
        json_response(['ok' => false, 'message' => 'La operación que intenta realizar no está permitida o no es inválida.'], 409, $config);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function notas_traslado_personal_id_usuario(PDO $pdo, array $user): string
{
    $mail = trim((string) ($user['Mail'] ?? ''));
    $nombreCompleto = trim((string) ($user['Nombre'] ?? '') . ' ' . (string) ($user['Apellido'] ?? ''));
    if ($mail === '' && $nombreCompleto === '') {
        return '';
    }

    $stmt = $pdo->prepare(
        'SELECT Id
         FROM Personal
         WHERE Estado = 1
           AND (
                Email = :mail
                OR EmailCorporativo = :mail
                OR CONCAT(Nombre, " ", Apellido) = :nombre
                OR CONCAT(Apellido, " ", Nombre) = :nombre
           )
         LIMIT 1'
    );
    $stmt->execute([
        'mail' => $mail,
        'nombre' => $nombreCompleto,
    ]);
    $id = $stmt->fetchColumn();
    return is_string($id) ? $id : '';
}

function handle_notas_traslado_entregado(PDO $pdo, array $config, string $notaId): void
{
    $auth = notas_traslado_require_auth($pdo, $config);
    notas_traslado_require_role($auth['roles'], $config);

    if (!notas_traslado_has_role($auth['roles'], 'NOTA_TRASLADO_TRANSPORTE') && !notas_traslado_is_admin($auth['roles'])) {
        json_response(['message' => 'Sin permisos para entregar traslados'], 403, $config);
        return;
    }

    $notaId = notas_traslado_extract_id($notaId);
    $usuarioId = (string) $auth['user']['Id'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT Id, NumeroNota, Externo, StatusNotaTrasladoId, TransportistaAsignadoId FROM NotasTraslado WHERE Id = :id AND Estado = 1 LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $notaId]);
        $nota = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$nota) {
            $pdo->rollBack();
            json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
            return;
        }

        if (!(bool) $nota['Externo'] || (int) $nota['StatusNotaTrasladoId'] !== NT_STATUS_EN_TRANSITO || (string) $nota['TransportistaAsignadoId'] !== $usuarioId) {
            $pdo->rollBack();
            json_response(['message' => 'El traslado no puede marcarse como entregado.'], 409, $config);
            return;
        }

        notas_traslado_actualizar_estado_y_traza(
            $pdo,
            $notaId,
            NT_STATUS_ENTREGADO,
            $usuarioId,
            'ENTREGA_EXTERNA',
            'El traslado fue entregado al destinatario.',
            [],
            'Cambio estado Nota de Traslado: ' . (string) $nota['NumeroNota'] . ' a ' . notas_traslado_status_label(NT_STATUS_ENTREGADO)
        );
        $pdo->commit();
        json_response(['ok' => true, 'message' => 'Entrega registrada correctamente.'], 200, $config);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function handle_notas_traslado_documento(PDO $pdo, array $config, string $notaId): void
{
    $auth = notas_traslado_require_auth($pdo, $config);
    notas_traslado_require_role($auth['roles'], $config);

    $notaId = notas_traslado_extract_id($notaId);
    if ($notaId === '') {
        json_response(['message' => 'Nota de Traslado no válida'], 400, $config);
        return;
    }

    $usuarioId = (string) $auth['user']['Id'];
    $depositoId = (string) ($auth['user']['DepositoId'] ?? '');

    $stmt = $pdo->prepare(notas_traslado_base_select() . ' WHERE n.Id = :id AND n.Estado = 1 LIMIT 1');
    $stmt->execute(['id' => $notaId]);
    $nota = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$nota) {
        json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
        return;
    }

    if (!notas_traslado_puede_ver($nota, $auth['roles'], $usuarioId, $depositoId)) {
        json_response(['message' => 'Sin permisos para visualizar la Nota de Traslado'], 403, $config);
        return;
    }

    if ((int) $nota['StatusNotaTrasladoId'] === NT_STATUS_ANULADA) {
        json_response(['message' => 'La Nota de Traslado se encuentra anulada'], 409, $config);
        return;
    }

    $fileStmt = $pdo->prepare('SELECT FileNota FROM NotasTraslado WHERE Id = :id AND Estado = 1 LIMIT 1');
    $fileStmt->execute(['id' => $notaId]);
    $pdf = (string) ($fileStmt->fetchColumn() ?: '');
    if ($pdf === '') {
        json_response(['message' => 'La Nota de Traslado no tiene archivo PDF generado. Edite y guarde la nota para regenerarlo.'], 404, $config);
        return;
    }

    $fileName = 'NotaTraslado_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $nota['NumeroNota']) . '.pdf';

    apply_cors($config);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fileName . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function handle_notas_traslado_routes(PDO $pdo, array $config, string $path, string $method): bool
{
    if ($method === 'GET' && $path === '/notas-traslado') {
        handle_notas_traslado_list($pdo, $config);
        return true;
    }

    if ($method === 'POST' && $path === '/notas-traslado/scan') {
        handle_notas_traslado_scan($pdo, $config);
        return true;
    }

    if ($method === 'POST' && preg_match('#^/notas-traslado/([^/]+)/entregado$#', $path, $m)) {
        handle_notas_traslado_entregado($pdo, $config, $m[1]);
        return true;
    }

    if ($method === 'GET' && preg_match('#^/notas-traslado/([^/]+)/documento$#', $path, $m)) {
        handle_notas_traslado_documento($pdo, $config, $m[1]);
        return true;
    }

    return false;
}
