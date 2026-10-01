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
const NT_STATUS_PENDIENTE_APROBACION = 8;
const NT_STATUS_RECHAZADA = 9;
const NT_TIPO_ENVIO_INTERNO = 'interno';
const NT_TIPO_ENVIO_EXTERNO = 'externo';
const NT_TIPO_ENVIO_EMPLEADO = 'empleado';

function notas_traslado_tipo_envio(array $nota): string
{
    $tipo = strtolower(trim((string) ($nota['TipoEnvio'] ?? $nota['tipoEnvio'] ?? '')));
    return in_array($tipo, [NT_TIPO_ENVIO_INTERNO, NT_TIPO_ENVIO_EXTERNO, NT_TIPO_ENVIO_EMPLEADO], true)
        ? $tipo
        : NT_TIPO_ENVIO_INTERNO;
}

function notas_traslado_es_empleado(array $nota): bool
{
    return notas_traslado_tipo_envio($nota) === NT_TIPO_ENVIO_EMPLEADO;
}

function notas_traslado_es_externo(array $nota): bool
{
    return in_array(notas_traslado_tipo_envio($nota), [NT_TIPO_ENVIO_EXTERNO, NT_TIPO_ENVIO_EMPLEADO], true);
}

function notas_traslado_es_interno(array $nota): bool
{
    return notas_traslado_tipo_envio($nota) === NT_TIPO_ENVIO_INTERNO;
}

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
    $allowed = [
        'SYSADMIN',
        'ADMIN',
        'NOTA_TRASLADO',
        'NOTA_TRASLADO_FULL_USERS',
        'NOTA_TRASLADO_DESPACHO',
        'NOTA_TRASLADO_TRANSPORTE',
        'NOTA_TRASLADO_APROBACION',
        'EMPLEADO',
    ];
    $upper = array_map(static fn ($r) => strtoupper(trim((string) $r)), $roles);
    if (!user_has_any_role($roles)) {
        json_response(['message' => 'Sin roles asignados. Contacte al administrador del sistema.'], 403, $config);
        exit;
    }

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

function notas_traslado_normalizar_mail(mixed $mail): string
{
    return strtolower(trim((string) $mail));
}

function notas_traslado_es_receptor(array $nota, string $usuarioId, string $usuarioMail): bool
{
    if ($usuarioId !== '' && strtolower((string) ($nota['UserRecibeId'] ?? '')) === strtolower($usuarioId)) {
        return true;
    }

    $mailReceptor = notas_traslado_normalizar_mail($nota['UserRecibeMail'] ?? '');
    $mailUsuario = notas_traslado_normalizar_mail($usuarioMail);

    return $mailReceptor !== '' && $mailUsuario !== '' && $mailReceptor === $mailUsuario;
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
        NT_STATUS_PENDIENTE_APROBACION => 'Pendiente Aprobacion',
        NT_STATUS_RECHAZADA => 'Rechazada',
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

function notas_traslado_pdf_text_width(string $text, int $size, bool $bold = false): float
{
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if (is_string($converted)) {
            $text = $converted;
        }
    }

    $units = 0.0;
    foreach (str_split($text) as $char) {
        $units += match (true) {
            $char === ' ' => 0.278,
            str_contains("ilI.,:;!'|", $char) => 0.278,
            str_contains('frtJj', $char) => 0.36,
            str_contains('mwMW', $char) => 0.84,
            ctype_upper($char) => 0.68,
            ctype_digit($char) => 0.56,
            default => 0.52,
        };
    }

    return $units * $size * ($bold ? 1.02 : 1.0);
}

function notas_traslado_pdf_text_right(string &$content, float $right, float $y, string $text, int $size = 10, bool $bold = false, string $rgb = '0 0 0'): void
{
    $x = max(32, $right - notas_traslado_pdf_text_width($text, $size, $bold));
    notas_traslado_pdf_text($content, $x, $y, $text, $size, $bold, $rgb);
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

function notas_traslado_first_existing_file(array $paths): ?string
{
    foreach ($paths as $path) {
        $path = trim((string) $path);
        if ($path !== '' && is_file($path)) {
            return $path;
        }
    }

    return null;
}

function notas_traslado_pdf_jpeg_resource(string $name, ?string $path): ?array
{
    if ($path === null || !is_file($path)) {
        return null;
    }

    $info = @getimagesize($path);
    if (!is_array($info) || ($info[2] ?? null) !== IMAGETYPE_JPEG) {
        return null;
    }

    $data = @file_get_contents($path);
    if (!is_string($data) || $data === '') {
        return null;
    }

    return [
        'name' => $name,
        'width' => (int) $info[0],
        'height' => (int) $info[1],
        'data' => $data,
    ];
}

function notas_traslado_pdf_image(string &$content, string $name, float $x, float $y, float $w, float $h): void
{
    $content .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $y, $name);
}

function notas_traslado_pdf_circle(string &$content, float $centerX, float $centerY, float $radius, string $fill = '1 1 1'): void
{
    $k = 0.5522847498 * $radius;
    $content .= sprintf(
        "%s rg %.2F %.2F m %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F %.2F %.2F %.2F %.2F c f\n0 0 0 rg\n",
        $fill,
        $centerX + $radius,
        $centerY,
        $centerX + $radius,
        $centerY + $k,
        $centerX + $k,
        $centerY + $radius,
        $centerX,
        $centerY + $radius,
        $centerX - $k,
        $centerY + $radius,
        $centerX - $radius,
        $centerY + $k,
        $centerX - $radius,
        $centerY,
        $centerX - $radius,
        $centerY - $k,
        $centerX - $k,
        $centerY - $radius,
        $centerX,
        $centerY - $radius,
        $centerX + $k,
        $centerY - $radius,
        $centerX + $radius,
        $centerY - $k,
        $centerX + $radius,
        $centerY
    );
}

function notas_traslado_qr_gf_tables(): array
{
    static $tables = null;
    if ($tables !== null) {
        return $tables;
    }

    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) {
            $x ^= 0x11D;
        }
    }
    for ($i = 255; $i < 512; $i++) {
        $exp[$i] = $exp[$i - 255];
    }

    return $tables = [$exp, $log];
}

function notas_traslado_qr_gf_mul(int $a, int $b): int
{
    if ($a === 0 || $b === 0) {
        return 0;
    }

    [$exp, $log] = notas_traslado_qr_gf_tables();
    return $exp[$log[$a] + $log[$b]];
}

function notas_traslado_qr_reed_solomon(array $data, int $eccCount): array
{
    [$exp] = notas_traslado_qr_gf_tables();
    $gen = [1];
    for ($i = 0; $i < $eccCount; $i++) {
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $coef) {
            $next[$j] ^= notas_traslado_qr_gf_mul($coef, 1);
            $next[$j + 1] ^= notas_traslado_qr_gf_mul($coef, $exp[$i]);
        }
        $gen = $next;
    }

    $ecc = array_fill(0, $eccCount, 0);
    foreach ($data as $byte) {
        $factor = $byte ^ $ecc[0];
        array_shift($ecc);
        $ecc[] = 0;
        for ($i = 0; $i < $eccCount; $i++) {
            $ecc[$i] ^= notas_traslado_qr_gf_mul($gen[$i + 1], $factor);
        }
    }

    return $ecc;
}

function notas_traslado_qr_append_bits(array &$bits, int $value, int $length): void
{
    for ($i = $length - 1; $i >= 0; $i--) {
        $bits[] = (($value >> $i) & 1) === 1;
    }
}

function notas_traslado_qr_set(array &$matrix, array &$reserved, int $row, int $col, bool $dark): void
{
    $matrix[$row][$col] = $dark;
    $reserved[$row][$col] = true;
}

function notas_traslado_qr_finder(array &$matrix, array &$reserved, int $row, int $col): void
{
    for ($r = -1; $r <= 7; $r++) {
        for ($c = -1; $c <= 7; $c++) {
            $rr = $row + $r;
            $cc = $col + $c;
            if ($rr < 0 || $rr >= 33 || $cc < 0 || $cc >= 33) {
                continue;
            }
            $dark = ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6)
                && ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
            notas_traslado_qr_set($matrix, $reserved, $rr, $cc, $dark);
        }
    }
}

function notas_traslado_qr_alignment(array &$matrix, array &$reserved, int $row, int $col): void
{
    for ($r = -2; $r <= 2; $r++) {
        for ($c = -2; $c <= 2; $c++) {
            $dark = max(abs($r), abs($c)) !== 1;
            notas_traslado_qr_set($matrix, $reserved, $row + $r, $col + $c, $dark);
        }
    }
}

function notas_traslado_qr_format_bits(): int
{
    $data = (1 << 3); // ECC L, mask 0
    $value = $data << 10;
    $generator = 0x537;
    for ($i = 14; $i >= 10; $i--) {
        if (($value >> $i) & 1) {
            $value ^= $generator << ($i - 10);
        }
    }

    return (($data << 10) | $value) ^ 0x5412;
}

function notas_traslado_qr_matrix(string $text): array
{
    $size = 33; // Version 4-L
    $matrix = array_fill(0, $size, array_fill(0, $size, false));
    $reserved = array_fill(0, $size, array_fill(0, $size, false));

    notas_traslado_qr_finder($matrix, $reserved, 0, 0);
    notas_traslado_qr_finder($matrix, $reserved, 0, $size - 7);
    notas_traslado_qr_finder($matrix, $reserved, $size - 7, 0);
    notas_traslado_qr_alignment($matrix, $reserved, 26, 26);

    for ($i = 8; $i < $size - 8; $i++) {
        notas_traslado_qr_set($matrix, $reserved, 6, $i, $i % 2 === 0);
        notas_traslado_qr_set($matrix, $reserved, $i, 6, $i % 2 === 0);
    }
    notas_traslado_qr_set($matrix, $reserved, 25, 8, true);

    for ($i = 0; $i < 9; $i++) {
        if ($i !== 6) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
    }
    for ($i = 0; $i < 8; $i++) {
        $reserved[$size - 1 - $i][8] = true;
        $reserved[8][$size - 1 - $i] = true;
    }

    $bytes = array_values(unpack('C*', $text) ?: []);
    $bits = [];
    notas_traslado_qr_append_bits($bits, 0x4, 4);
    notas_traslado_qr_append_bits($bits, count($bytes), 8);
    foreach ($bytes as $byte) {
        notas_traslado_qr_append_bits($bits, $byte, 8);
    }
    $capacityBits = 80 * 8;
    for ($i = 0; $i < 4 && count($bits) < $capacityBits; $i++) {
        $bits[] = false;
    }
    while (count($bits) % 8 !== 0) {
        $bits[] = false;
    }

    $data = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $byte = 0;
        for ($j = 0; $j < 8; $j++) {
            $byte = ($byte << 1) | ($bits[$i + $j] ? 1 : 0);
        }
        $data[] = $byte;
    }
    for ($pad = 0xEC; count($data) < 80; $pad = $pad === 0xEC ? 0x11 : 0xEC) {
        $data[] = $pad;
    }
    $codewords = array_merge($data, notas_traslado_qr_reed_solomon($data, 20));
    $dataBits = [];
    foreach ($codewords as $byte) {
        notas_traslado_qr_append_bits($dataBits, $byte, 8);
    }

    $bitIndex = 0;
    $upward = true;
    for ($col = $size - 1; $col > 0; $col -= 2) {
        if ($col === 6) {
            $col--;
        }
        for ($i = 0; $i < $size; $i++) {
            $row = $upward ? $size - 1 - $i : $i;
            for ($offset = 0; $offset < 2; $offset++) {
                $cc = $col - $offset;
                if ($reserved[$row][$cc]) {
                    continue;
                }
                $dark = $dataBits[$bitIndex] ?? false;
                if (($row + $cc) % 2 === 0) {
                    $dark = !$dark;
                }
                $matrix[$row][$cc] = $dark;
                $bitIndex++;
            }
        }
        $upward = !$upward;
    }

    $format = notas_traslado_qr_format_bits();
    for ($i = 0; $i < 15; $i++) {
        $dark = (($format >> $i) & 1) === 1;
        if ($i < 6) {
            $matrix[$i][8] = $dark;
        } elseif ($i < 8) {
            $matrix[$i + 1][8] = $dark;
        } else {
            $matrix[$size - 15 + $i][8] = $dark;
        }

        if ($i < 8) {
            $matrix[8][$size - $i - 1] = $dark;
        } elseif ($i === 8) {
            $matrix[8][8] = $dark;
        } else {
            $matrix[8][14 - $i] = $dark;
        }
    }
    $matrix[$size - 8][8] = true;

    return $matrix;
}

function notas_traslado_pdf_qr(string &$content, float $x, float $y, string $text, float $module = 3.0): void
{
    $matrix = notas_traslado_qr_matrix($text);
    foreach ($matrix as $row => $cols) {
        foreach ($cols as $col => $dark) {
            if ($dark) {
                $content .= sprintf("0 0 0 rg %.2F %.2F %.2F %.2F re f\n", $x + ($col * $module), $y - (($row + 1) * $module), $module, $module);
            }
        }
    }
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
    $assetsDir = dirname(__DIR__) . '/assets';
    $images = array_values(array_filter([
        notas_traslado_pdf_jpeg_resource('ImLogo', notas_traslado_first_existing_file([
            $assetsDir . '/logoRaei.jpg',
            dirname(__DIR__, 2) . '/img/logoRaei.jpg',
        ])),
        notas_traslado_pdf_jpeg_resource('ImCert', notas_traslado_first_existing_file([
            $assetsDir . '/certificacion.jpg',
            dirname(__DIR__, 2) . '/img/certificacion.jpg',
        ])),
        notas_traslado_pdf_jpeg_resource('ImQrIcon', notas_traslado_first_existing_file([
            $assetsDir . '/IconRAEImini.jpg',
            dirname(__DIR__, 2) . '/img/IconRAEImini.jpg',
        ])),
    ]));
    $imageNames = array_column($images, 'name');

    if (in_array('ImLogo', $imageNames, true)) {
        notas_traslado_pdf_image($content, 'ImLogo', 40, 724, 150, 72.58);
    } else {
        notas_traslado_pdf_text($content, 40, 770, 'RAEI', 26, true, '0.05 0.24 0.52');
    }
    notas_traslado_pdf_text($content, 40, 708, 'METALURGICA RAEI S.A.', 11, true);
    notas_traslado_pdf_text($content, 40, 693, 'CUIT: 30-51663575-0');
    notas_traslado_pdf_text($content, 40, 678, 'IVA RESPONSABLE INSCRIPTO');

    notas_traslado_pdf_rect($content, 238, 681, 115, 115, '0.82 0.84 0.86', '1 1 1');
    notas_traslado_pdf_qr($content, 251, 784, (string) $nota['Id'], 2.7);
    if (in_array('ImQrIcon', $imageNames, true)) {
        notas_traslado_pdf_circle($content, 295.5, 739.45, 10);
        notas_traslado_pdf_image($content, 'ImQrIcon', 288.25, 732.2, 14.5, 14.5);
    }

    notas_traslado_pdf_text_right($content, 555, 780, 'NOTA DE TRASLADO', 17, true, '0.05 0.24 0.52');
    notas_traslado_pdf_text_right($content, 555, 754, 'Nro. ' . (string) $nota['NumeroNota'], 13, true, '0.05 0.24 0.52');
    notas_traslado_pdf_text_right($content, 555, 735, 'Documento no valido como factura', 9, false, '0.35 0.37 0.40');
    notas_traslado_pdf_text_right($content, 555, 711, 'Fecha: ' . date('d/m/Y', strtotime((string) $nota['Fecha'])), 10, true);
    if (in_array('ImCert', $imageNames, true)) {
        notas_traslado_pdf_image($content, 'ImCert', 455, 663, 100, 36.78);
    }

    notas_traslado_pdf_line($content, 32, 653, 563, 653);

    $destino = notas_traslado_es_empleado($nota)
        ? 'Empleado'
        : (notas_traslado_es_externo($nota)
        ? ((string) ($nota['EmpresaNombre'] ?? $nota['ReceptorExterno'] ?? 'Externo'))
        : ((string) ($nota['Destino'] ?? '-')));

    notas_traslado_pdf_summary_box($content, 40, 593, 250, 'Origen', (string) ($nota['Origen'] ?? '-'));
    notas_traslado_pdf_summary_box($content, 305, 593, 250, 'Destino', $destino);
    notas_traslado_pdf_summary_box($content, 40, 537, 250, 'Envia', (string) ($nota['NombreEnvia'] ?? '-'));
    notas_traslado_pdf_summary_box($content, 305, 537, 250, 'Recibe', (string) ($nota['NombreRecibe'] ?? '-'));

    $y = 505.0;
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

    if (notas_traslado_es_externo($nota)) {
        $y -= 18;
        notas_traslado_pdf_rect($content, 40, $y - 118, 515, 112);
        notas_traslado_pdf_text($content, 52, $y - 24, 'Recepcion externa', 10, true, '0.35 0.37 0.40');
        notas_traslado_pdf_line($content, 70, $y - 88, 200, $y - 88, '0.25 0.25 0.25');
        notas_traslado_pdf_line($content, 235, $y - 88, 365, $y - 88, '0.25 0.25 0.25');
        notas_traslado_pdf_line($content, 400, $y - 88, 530, $y - 88, '0.25 0.25 0.25');
        notas_traslado_pdf_text($content, 92, $y - 104, 'Fecha de Recepcion', 8, false, '0.35 0.37 0.40');
        notas_traslado_pdf_text($content, 265, $y - 104, 'Firma del Receptor', 8, false, '0.35 0.37 0.40');
        notas_traslado_pdf_text($content, 446, $y - 104, 'Aclaracion', 8, false, '0.35 0.37 0.40');
        $y -= 130;
    }

    $autorizante = trim((string) ($nota['UsuarioAprobacionNombre'] ?? ''));
    if ($autorizante !== '') {
        if ($y < 90) {
            $pages[] = $content;
            $content = '';
            $y = 802.0;
        }

        $y -= 18;
        notas_traslado_pdf_text($content, 40, $y, 'Autorizado por: ' . $autorizante, 10, true);
        $y -= 12;
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
        $xObjects = [];
        foreach ($images as $imageIndex => $image) {
            $xObjects[] = '/' . $image['name'] . ' ' . ($fontBoldId + 1 + $imageIndex) . ' 0 R';
        }
        $xObjectResource = $xObjects !== [] ? ' /XObject << ' . implode(' ', $xObjects) . ' >>' : '';
        $objects[$pageObjId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $fontRegularId . ' 0 R /F2 ' . $fontBoldId . ' 0 R >>' . $xObjectResource . ' >> /Contents ' . $contentObjId . ' 0 R >>';
        $objects[$contentObjId] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
    }

    $objects[2] = '<< /Type /Pages /Count ' . $pageCount . ' /Kids [' . implode(' ', $kids) . '] >>';
    $objects[$fontRegularId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
    $objects[$fontBoldId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
    foreach ($images as $imageIndex => $image) {
        $objects[$fontBoldId + 1 + $imageIndex] = '<< /Type /XObject /Subtype /Image /Width ' . $image['width'] . ' /Height ' . $image['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($image['data']) . " >>\nstream\n" . $image['data'] . "\nendstream";
    }
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

function notas_traslado_map_row(array $row, array $context = []): array
{
    $statusId = (int) $row['StatusNotaTrasladoId'];
    $fechaActualizacion = strtotime((string) ($row['FechaActualizacion'] ?? $row['Fecha']));
    $roles = $context['roles'] ?? [];
    $usuarioId = (string) ($context['usuarioId'] ?? '');
    $usuarioMail = (string) ($context['usuarioMail'] ?? '');
    $depositoId = (string) ($context['depositoId'] ?? '');
    $isAdmin = notas_traslado_is_admin($roles);
    $isDespacho = notas_traslado_has_role($roles, 'NOTA_TRASLADO_DESPACHO');
    $isTransporte = notas_traslado_has_role($roles, 'NOTA_TRASLADO_TRANSPORTE');
    $isAprobador = $isAdmin || notas_traslado_has_role($roles, 'NOTA_TRASLADO_APROBACION');
    $mismaSucursalOrigen = $depositoId !== ''
        && strtolower((string) ($row['UserAddDepositoId'] ?? '')) === strtolower($depositoId);
    $esReceptorInterno = notas_traslado_es_receptor($row, $usuarioId, $usuarioMail);
    $esTransportistaAsignado = $usuarioId !== ''
        && strtolower((string) ($row['TransportistaAsignadoId'] ?? '')) === strtolower($usuarioId);

    return [
        'id' => (string) $row['Id'],
        'numeroNota' => (string) $row['NumeroNota'],
        'fecha' => substr((string) $row['Fecha'], 0, 10),
        'origen' => (string) ($row['Origen'] ?? '-'),
        'destino' => (string) ($row['Destino'] ?? '-'),
        'tipoEnvio' => notas_traslado_tipo_envio($row),
        'destinoId' => $row['DestinoId'] !== null ? (string) $row['DestinoId'] : null,
        'userAdd' => (string) $row['UserAdd'],
        'userRecibeId' => $row['UserRecibeId'] !== null ? (string) $row['UserRecibeId'] : null,
        'personalRecibeId' => $row['PersonalRecibeId'] !== null ? (string) $row['PersonalRecibeId'] : null,
        'documentoFirmado' => $row['DocumentoFirmado'] !== null ? (string) $row['DocumentoFirmado'] : null,
        'nombreEnvia' => (string) $row['NombreEnvia'],
        'nombreRecibe' => (string) $row['NombreRecibe'],
        'statusId' => $statusId,
        'status' => notas_traslado_status_label($statusId),
        'fechaActualizacion' => $fechaActualizacion !== false ? date(DATE_ATOM, $fechaActualizacion) : substr((string) $row['Fecha'], 0, 10),
        'usuarioActualizacion' => trim((string) ($row['UsuarioActualizacion'] ?? '')),
        'transportistaAsignadoId' => $row['TransportistaAsignadoId'] !== null ? (string) $row['TransportistaAsignadoId'] : null,
        'fechaAsignacionTransporte' => $row['FechaAsignacionTransporte'] !== null ? (string) $row['FechaAsignacionTransporte'] : null,
        'puedeEntregar' => (
            (notas_traslado_es_externo($row) && $statusId === NT_STATUS_EN_TRANSITO && ($isAdmin || ($isTransporte && $esTransportistaAsignado)))
            || (notas_traslado_es_interno($row) && $statusId === NT_STATUS_RECEPCION_SEDE && ($isAdmin || ($isDespacho && strtolower((string) ($row['DestinoId'] ?? '')) === strtolower($depositoId))))
        ),
        'puedeRecibir' => notas_traslado_es_interno($row) && $statusId === NT_STATUS_ENTREGADO && $esReceptorInterno,
        'puedeAprobar' => notas_traslado_es_empleado($row) && $statusId === NT_STATUS_PENDIENTE_APROBACION && $isAprobador,
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

function notas_traslado_registrar_notificacion(PDO $pdo, string $usuarioId, string $titulo, string $mensaje, string $tipo = 'info', ?string $urlDestino = null): void
{
    if (trim($usuarioId) === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO RealTimeNotifications (UsuarioId, Titulo, Mensaje, UrlDestino, Tipo, FechaEnvio)
         VALUES (:usuarioId, :titulo, :mensaje, :urlDestino, :tipo, NOW())'
    );
    $stmt->execute([
        'usuarioId' => $usuarioId,
        'titulo' => $titulo,
        'mensaje' => $mensaje,
        'urlDestino' => $urlDestino,
        'tipo' => $tipo,
    ]);
}

function notas_traslado_solicitar_regeneracion_pdf_web(array $config, string $notaId): bool
{
    $baseUrl = trim((string) ($config['sgi_web_base_url'] ?? ''));
    $baseUrls = array_values(array_unique(array_filter([
        $baseUrl,
        'https://sgi.raei.com.ar',
    ], static fn ($url) => trim((string) $url) !== '')));

    if ($baseUrls === []) {
        error_log('[notas_traslado] SGI_WEB_BASE_URL no configurado; no se pudo regenerar PDF web para nota ' . $notaId);
        return false;
    }

    if (!function_exists('curl_init')) {
        error_log('[notas_traslado] cURL no disponible; no se pudo regenerar PDF web para nota ' . $notaId);
        return false;
    }

    $apiKey = config_api_key($config);
    if ($apiKey === '') {
        error_log('[notas_traslado] API key no configurada; no se pudo regenerar PDF web para nota ' . $notaId);
        return false;
    }

    foreach ($baseUrls as $candidateBaseUrl) {
        $url = rtrim((string) $candidateBaseUrl, '/') . '/NotasTraslado/RegenerarPdf/' . rawurlencode($notaId);
        $curl = curl_init($url);
        if ($curl === false) {
            error_log('[notas_traslado] No se pudo iniciar cURL para regenerar PDF web de nota ' . $notaId . ' URL=' . $url);
            continue;
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-API-KEY: ' . $apiKey,
            ],
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false || $status < 200 || $status >= 300) {
            error_log('[notas_traslado] Fallo regenerando PDF web de nota ' . $notaId . '. URL=' . $url . ' HTTP=' . $status . ' Error=' . $error . ' Body=' . substr((string) $body, 0, 300));
            continue;
        }

        $json = json_decode((string) $body, true);
        if (is_array($json) && array_key_exists('ok', $json) && $json['ok'] !== true) {
            error_log('[notas_traslado] SGI web rechazo regeneracion de PDF de nota ' . $notaId . '. URL=' . $url . ' Body=' . substr((string) $body, 0, 300));
            continue;
        }

        return true;
    }

    return false;
}

function notas_traslado_obtener_pdf_blob(PDO $pdo, string $notaId): string
{
    $fileStmt = $pdo->prepare('SELECT FileNota FROM NotasTraslado WHERE Id = :id AND Estado = 1 LIMIT 1');
    $fileStmt->execute(['id' => $notaId]);
    return (string) ($fileStmt->fetchColumn() ?: '');
}

function notas_traslado_generar_pdf_api(PDO $pdo, string $notaId): string
{
    $stmt = $pdo->prepare(notas_traslado_base_select() . ' WHERE n.Id = :id AND n.Estado = 1 LIMIT 1');
    $stmt->execute(['id' => $notaId]);
    $nota = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$nota) {
        return '';
    }

    $detStmt = $pdo->prepare(
        'SELECT Cantidad, Detalle
         FROM NotaTrasladoDetalle
         WHERE IdNotaTraslado = :id AND Estado = 1
         ORDER BY Id'
    );
    $detStmt->execute(['id' => $notaId]);
    $detalles = $detStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $pdf = notas_traslado_pdf_build($nota, $detalles);
    if ($pdf === '') {
        return '';
    }

    $upd = $pdo->prepare('UPDATE NotasTraslado SET FileNota = :pdf WHERE Id = :id AND Estado = 1');
    $upd->bindValue('pdf', $pdf, PDO::PARAM_LOB);
    $upd->bindValue('id', $notaId);
    $upd->execute();

    return $pdf;
}

function notas_traslado_regenerar_y_obtener_pdf(PDO $pdo, array $config, string $notaId): string
{
    return notas_traslado_generar_pdf_api($pdo, $notaId);
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
    return 'SELECT n.Id, n.NumeroNota, n.Fecha, n.tipoEnvio AS TipoEnvio, n.OrigenId, n.DestinoId, n.PersonalRecibeId, n.NombreEnvia, n.NombreRecibe, n.UserRecibeId,
                   n.UserAdd, n.ReceptorExterno, n.DocumentoFirmado, ur.Mail AS UserRecibeMail, COALESCE(n.DateMod, n.DateAdd) AS FechaActualizacion,
                   CONCAT(COALESCE(um.Nombre, ua.Nombre), " ", COALESCE(um.Apellido, ua.Apellido)) AS UsuarioActualizacion,
                   e.Nombre AS EmpresaNombre,
                   CONCAT(pr.Apellido, " ", pr.Nombre) AS PersonalRecibeNombre,
                   CONCAT(uap.Nombre, " ", uap.Apellido) AS UsuarioAprobacionNombre,
                   ua.DepositoId AS UserAddDepositoId,
                   n.StatusNotaTrasladoId, n.TransportistaAsignadoId, n.FechaAsignacionTransporte,
                   o.Nombre AS Origen,
                   CASE WHEN n.tipoEnvio = "empleado" THEN "Empleado"
                        WHEN n.tipoEnvio IN ("externo", "empleado") THEN COALESCE(e.Nombre, n.ReceptorExterno, "Externo")
                        ELSE COALESCE(d.Nombre, "-") END AS Destino
            FROM NotasTraslado n
            INNER JOIN Usuarios ua ON ua.Id = n.UserAdd
            LEFT JOIN Usuarios ur ON ur.Id = n.UserRecibeId
            LEFT JOIN Usuarios um ON um.Id = n.UserMod
            LEFT JOIN Depositos o ON o.Id = n.OrigenId
            LEFT JOIN Depositos d ON d.Id = n.DestinoId
            LEFT JOIN Empresa e ON e.Id = n.EmpresaId
            LEFT JOIN Personal pr ON pr.Id = n.PersonalRecibeId
            LEFT JOIN Usuarios uap ON uap.Id = n.UsuarioAprobacionId';
}

function notas_traslado_puede_ver(array $nota, array $roles, string $usuarioId, string $depositoId, string $usuarioMail = ''): bool
{
    if (notas_traslado_is_admin($roles) || notas_traslado_has_role($roles, 'NOTA_TRASLADO_FULL_USERS')) {
        return true;
    }

    if (strtolower((string) $nota['UserAdd']) === strtolower($usuarioId)) {
        return true;
    }

    if (notas_traslado_es_receptor($nota, $usuarioId, $usuarioMail)) {
        return true;
    }

    $status = (int) $nota['StatusNotaTrasladoId'];
    if (
        notas_traslado_es_empleado($nota)
        && $status === NT_STATUS_PENDIENTE_APROBACION
        && notas_traslado_has_role($roles, 'NOTA_TRASLADO_APROBACION')
    ) {
        return true;
    }

    if (
        notas_traslado_has_role($roles, 'NOTA_TRASLADO_TRANSPORTE')
        && !in_array($status, [NT_STATUS_RECEPCION_SEDE, NT_STATUS_RECIBIDA], true)
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
        && in_array($status, [NT_STATUS_GENERADA, NT_STATUS_RECEPCION_DESPACHO], true)
    ) || (
        $mismaSucursalDestino
        && in_array($status, [NT_STATUS_EN_TRANSITO, NT_STATUS_RECEPCION_SEDE], true)
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
    $usuarioMail = (string) ($auth['user']['Mail'] ?? '');
    $depositoId = (string) ($auth['user']['DepositoId'] ?? '');
    $roles = $auth['roles'];
    $isAdmin = notas_traslado_is_admin($roles) || notas_traslado_has_role($roles, 'NOTA_TRASLADO_FULL_USERS');
    $isDespacho = notas_traslado_has_role($roles, 'NOTA_TRASLADO_DESPACHO');
    $isTransporte = notas_traslado_has_role($roles, 'NOTA_TRASLADO_TRANSPORTE');
    $isAprobador = notas_traslado_has_role($roles, 'NOTA_TRASLADO_APROBACION');

    $where = ['n.Estado = 1'];
    $params = [];

    if ($desde !== '' && $hasta !== '') {
        $where[] = 'DATE(n.Fecha) BETWEEN :desde AND :hasta';
        $params['desde'] = $desde;
        $params['hasta'] = $hasta;
    }

    if (!$mostrarTodos) {
        $where[] = 'n.StatusNotaTrasladoId NOT IN (' . NT_STATUS_RECIBIDA . ', ' . NT_STATUS_ANULADA . ')';
    }

    if (!$isAdmin) {
        $visibility = [$mostrarTodos
            ? 'n.UserAdd = :usuarioId'
            : '(n.UserAdd = :usuarioId AND n.StatusNotaTrasladoId <> ' . NT_STATUS_RECIBIDA . ')'];
        $params['usuarioId'] = $usuarioId;

        if ($isAprobador) {
            $visibility[] = $mostrarTodos
                ? '(n.tipoEnvio = "empleado" AND n.StatusNotaTrasladoId <> ' . NT_STATUS_ANULADA . ')'
                : '(n.tipoEnvio = "empleado" AND n.StatusNotaTrasladoId = ' . NT_STATUS_PENDIENTE_APROBACION . ')';
        }

        $visibility[] = $mostrarTodos
            ? '(n.UserRecibeId = :usuarioId OR LOWER(ur.Mail) = :usuarioMail)'
            : '((n.UserRecibeId = :usuarioId OR LOWER(ur.Mail) = :usuarioMail) AND n.tipoEnvio = "interno" AND n.StatusNotaTrasladoId <> ' . NT_STATUS_RECIBIDA . ')';
        $params['usuarioMail'] = notas_traslado_normalizar_mail($usuarioMail);

        if ($isTransporte) {
            $visibility[] = '(n.TransportistaAsignadoId = :transportistaId AND n.StatusNotaTrasladoId NOT IN (' . NT_STATUS_RECEPCION_SEDE . ',' . NT_STATUS_RECIBIDA . '))';
            $params['transportistaId'] = $usuarioId;
        }

        if ($isDespacho && $depositoId !== '') {
            if ($mostrarTodos) {
                $visibility[] = '(ua.DepositoId = :depositoId AND n.StatusNotaTrasladoId <> ' . NT_STATUS_ANULADA . ')';
                $visibility[] = '(n.DestinoId = :depositoId AND n.StatusNotaTrasladoId <> ' . NT_STATUS_ANULADA . ')';
            } else {
                $visibility[] = '(ua.DepositoId = :depositoId AND n.StatusNotaTrasladoId IN (' . NT_STATUS_GENERADA . ',' . NT_STATUS_RECEPCION_DESPACHO . '))';
                $visibility[] = '(n.DestinoId = :depositoId AND n.StatusNotaTrasladoId IN (' . NT_STATUS_EN_TRANSITO . ',' . NT_STATUS_RECEPCION_SEDE . '))';
            }
            $params['depositoId'] = $depositoId;
        }

        $where[] = '(' . implode(' OR ', $visibility) . ')';
    }

    $sql = notas_traslado_base_select() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY n.DateAdd DESC LIMIT 200';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $context = [
        'roles' => $roles,
        'usuarioId' => $usuarioId,
        'usuarioMail' => $usuarioMail,
        'depositoId' => $depositoId,
    ];
    json_response(['notas' => array_map(static fn ($row) => notas_traslado_map_row($row, $context), $rows)], 200, $config);
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
    $usuarioMail = (string) ($auth['user']['Mail'] ?? '');
    $depositoId = (string) ($auth['user']['DepositoId'] ?? '');
    $roles = $auth['roles'];
    $isTransporte = notas_traslado_has_role($roles, 'NOTA_TRASLADO_TRANSPORTE');
    $isDespacho = notas_traslado_has_role($roles, 'NOTA_TRASLADO_DESPACHO') || notas_traslado_is_admin($roles);

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

        if ($isDespacho && notas_traslado_es_interno($nota) && $status === NT_STATUS_EN_TRANSITO && ($isAdmin || $mismaSucursalDestino)) {
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

        if (notas_traslado_es_interno($nota) && $status === NT_STATUS_ENTREGADO && notas_traslado_es_receptor($nota, $usuarioId, $usuarioMail)) {
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

    $roles = $auth['roles'];
    if (
        !notas_traslado_has_role($roles, 'NOTA_TRASLADO_TRANSPORTE')
        && !notas_traslado_has_role($roles, 'NOTA_TRASLADO_DESPACHO')
        && !notas_traslado_is_admin($roles)
    ) {
        json_response(['message' => 'Sin permisos para entregar traslados'], 403, $config);
        return;
    }

    $notaId = notas_traslado_extract_id($notaId);
    $usuarioId = (string) $auth['user']['Id'];
    $depositoId = (string) ($auth['user']['DepositoId'] ?? '');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(notas_traslado_base_select() . ' WHERE n.Id = :id AND n.Estado = 1 LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $notaId]);
        $nota = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$nota) {
            $pdo->rollBack();
            json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
            return;
        }

        $status = (int) $nota['StatusNotaTrasladoId'];
        $isAdmin = notas_traslado_is_admin($roles);
        $puedeEntregarExterno = notas_traslado_es_externo($nota)
            && $status === NT_STATUS_EN_TRANSITO
            && ($isAdmin || (
                notas_traslado_has_role($roles, 'NOTA_TRASLADO_TRANSPORTE')
                && strtolower((string) $nota['TransportistaAsignadoId']) === strtolower($usuarioId)
            ));
        $puedeEntregarInterno = notas_traslado_es_interno($nota)
            && $status === NT_STATUS_RECEPCION_SEDE
            && ($isAdmin || (
                notas_traslado_has_role($roles, 'NOTA_TRASLADO_DESPACHO')
                && $depositoId !== ''
                && strtolower((string) $nota['DestinoId']) === strtolower($depositoId)
            ));

        if (!$puedeEntregarExterno && !$puedeEntregarInterno) {
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

function handle_notas_traslado_recibido(PDO $pdo, array $config, string $notaId): void
{
    $auth = notas_traslado_require_auth($pdo, $config);
    notas_traslado_require_role($auth['roles'], $config);

    $notaId = notas_traslado_extract_id($notaId);
    $usuarioId = (string) $auth['user']['Id'];
    $usuarioMail = (string) ($auth['user']['Mail'] ?? '');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'SELECT n.Id, n.NumeroNota, n.tipoEnvio AS TipoEnvio, n.StatusNotaTrasladoId, n.UserRecibeId, n.UserAdd, ur.Mail AS UserRecibeMail
             FROM NotasTraslado n
             LEFT JOIN Usuarios ur ON ur.Id = n.UserRecibeId
             WHERE n.Id = :id AND n.Estado = 1
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $notaId]);
        $nota = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$nota) {
            $pdo->rollBack();
            json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
            return;
        }

        if (
            notas_traslado_es_externo($nota)
            || (int) $nota['StatusNotaTrasladoId'] !== NT_STATUS_ENTREGADO
            || !notas_traslado_es_receptor($nota, $usuarioId, $usuarioMail)
        ) {
            $pdo->rollBack();
            json_response(['message' => 'El traslado no puede marcarse como recibido.'], 409, $config);
            return;
        }

        notas_traslado_actualizar_estado_y_traza(
            $pdo,
            $notaId,
            NT_STATUS_RECIBIDA,
            $usuarioId,
            'RECEPCION_FINAL',
            'La nota fue cerrada como recibida.',
            [],
            'Cambio estado Nota de Traslado: ' . (string) $nota['NumeroNota'] . ' a ' . notas_traslado_status_label(NT_STATUS_RECIBIDA)
        );

        notas_traslado_registrar_notificacion(
            $pdo,
            (string) $nota['UserAdd'],
            'Nota de Traslado recibida',
            'El envío de la Nota de Traslado ' . (string) $nota['NumeroNota'] . ' fue recibido exitosamente.',
            'success',
            '/NotasTraslado/Index'
        );

        $pdo->commit();
        json_response(['ok' => true, 'message' => 'Recepción registrada correctamente.'], 200, $config);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function handle_notas_traslado_aprobacion(PDO $pdo, array $config, string $notaId, bool $aprobar): void
{
    $auth = notas_traslado_require_auth($pdo, $config);
    notas_traslado_require_role($auth['roles'], $config);

    $roles = $auth['roles'];
    if (!notas_traslado_is_admin($roles) && !notas_traslado_has_role($roles, 'NOTA_TRASLADO_APROBACION')) {
        json_response(['message' => 'Sin permisos para aprobar notas de traslado'], 403, $config);
        return;
    }

    $notaId = notas_traslado_extract_id($notaId);
    $usuarioId = (string) $auth['user']['Id'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(notas_traslado_base_select() . ' WHERE n.Id = :id AND n.Estado = 1 LIMIT 1 FOR UPDATE');
        $stmt->execute(['id' => $notaId]);
        $nota = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$nota) {
            $pdo->rollBack();
            json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
            return;
        }

        if (!notas_traslado_es_empleado($nota) || (int) $nota['StatusNotaTrasladoId'] !== NT_STATUS_PENDIENTE_APROBACION) {
            $pdo->rollBack();
            json_response(['message' => 'La nota no se encuentra pendiente de aprobación.'], 409, $config);
            return;
        }

        $status = $aprobar ? NT_STATUS_GENERADA : NT_STATUS_RECHAZADA;
        notas_traslado_actualizar_estado_y_traza(
            $pdo,
            $notaId,
            $status,
            $usuarioId,
            $aprobar ? 'APROBACION' : 'RECHAZO_APROBACION',
            $aprobar ? 'La nota de traslado con destino a empleado fue aprobada.' : 'La nota de traslado con destino a empleado fue rechazada.',
            [
                'UsuarioAprobacionId' => $usuarioId,
                'FechaAprobacion' => '__NOW__',
            ],
            ($aprobar ? 'Aprobacion' : 'Rechazo') . ' Nota de Traslado a Empleado: ' . (string) $nota['NumeroNota']
        );

        $pdo->commit();
        $pdfSize = 0;
        if ($aprobar) {
            $pdf = notas_traslado_regenerar_y_obtener_pdf($pdo, $config, $notaId);
            $pdfSize = strlen($pdf);
        }

        json_response([
            'ok' => true,
            'message' => $aprobar
                ? ($pdfSize > 0 ? 'Nota de Traslado aprobada correctamente.' : 'Nota de Traslado aprobada, pero no se pudo generar el PDF.')
                : 'Nota de Traslado rechazada correctamente.',
            'pdfGenerado' => !$aprobar || $pdfSize > 0,
            'pdfSize' => $pdfSize,
        ], 200, $config);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function handle_notas_traslado_regenerar_pdf(PDO $pdo, array $config, string $notaId): void
{
    $auth = notas_traslado_require_auth($pdo, $config);
    notas_traslado_require_role($auth['roles'], $config);

    $notaId = notas_traslado_extract_id($notaId);
    if ($notaId === '') {
        json_response(['message' => 'Nota de Traslado no válida'], 400, $config);
        return;
    }

    $usuarioId = (string) $auth['user']['Id'];
    $usuarioMail = (string) ($auth['user']['Mail'] ?? '');
    $depositoId = (string) ($auth['user']['DepositoId'] ?? '');

    $stmt = $pdo->prepare(notas_traslado_base_select() . ' WHERE n.Id = :id AND n.Estado = 1 LIMIT 1');
    $stmt->execute(['id' => $notaId]);
    $nota = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$nota) {
        json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
        return;
    }

    if (!notas_traslado_puede_ver($nota, $auth['roles'], $usuarioId, $depositoId, $usuarioMail)) {
        json_response(['message' => 'Sin permisos para visualizar la Nota de Traslado'], 403, $config);
        return;
    }

    $statusNota = (int) $nota['StatusNotaTrasladoId'];
    if ($statusNota === NT_STATUS_ANULADA || $statusNota === NT_STATUS_RECHAZADA || $statusNota === NT_STATUS_PENDIENTE_APROBACION) {
        json_response(['message' => 'La Nota de Traslado no se encuentra en un estado válido para generar el PDF.'], 409, $config);
        return;
    }

    $pdf = notas_traslado_regenerar_y_obtener_pdf($pdo, $config, $notaId);
    if ($pdf !== '') {
        json_response(['ok' => true, 'message' => 'PDF generado correctamente.', 'pdfSize' => strlen($pdf)], 200, $config);
        return;
    }

    json_response(['message' => 'No se pudo generar el PDF de la Nota de Traslado.'], 500, $config);
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
    $usuarioMail = (string) ($auth['user']['Mail'] ?? '');
    $depositoId = (string) ($auth['user']['DepositoId'] ?? '');

    $stmt = $pdo->prepare(notas_traslado_base_select() . ' WHERE n.Id = :id AND n.Estado = 1 LIMIT 1');
    $stmt->execute(['id' => $notaId]);
    $nota = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$nota) {
        json_response(['message' => 'Nota de Traslado no encontrada'], 404, $config);
        return;
    }

    if (!notas_traslado_puede_ver($nota, $auth['roles'], $usuarioId, $depositoId, $usuarioMail)) {
        json_response(['message' => 'Sin permisos para visualizar la Nota de Traslado'], 403, $config);
        return;
    }

    if ((int) $nota['StatusNotaTrasladoId'] === NT_STATUS_ANULADA) {
        json_response(['message' => 'La Nota de Traslado se encuentra anulada'], 409, $config);
        return;
    }

    $statusNota = (int) $nota['StatusNotaTrasladoId'];
    $esDestinoEmpleado = notas_traslado_es_empleado($nota);
    $puedeVerPendienteAprobacion = notas_traslado_is_admin($auth['roles'])
        || notas_traslado_has_role($auth['roles'], 'NOTA_TRASLADO_APROBACION');

    if ($esDestinoEmpleado && $statusNota === NT_STATUS_RECHAZADA) {
        json_response(['message' => 'La Nota de Traslado con destino a empleado fue rechazada.'], 409, $config);
        return;
    }

    if ($esDestinoEmpleado && $statusNota === NT_STATUS_PENDIENTE_APROBACION && !$puedeVerPendienteAprobacion) {
        json_response(['message' => 'Solo un usuario aprobador puede visualizar el PDF pendiente de aprobación.'], 403, $config);
        return;
    }

    $pdf = notas_traslado_obtener_pdf_blob($pdo, $notaId);
    if ($pdf === '') {
        if ($esDestinoEmpleado && $statusNota === NT_STATUS_PENDIENTE_APROBACION && $puedeVerPendienteAprobacion) {
            $detStmt = $pdo->prepare(
                'SELECT Cantidad, Detalle
                 FROM NotaTrasladoDetalle
                 WHERE IdNotaTraslado = :id AND Estado = 1
                 ORDER BY Id'
            );
            $detStmt->execute(['id' => $notaId]);
            $detalles = $detStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $pdf = notas_traslado_pdf_build($nota, $detalles);
        } else {
            $pdf = notas_traslado_regenerar_y_obtener_pdf($pdo, $config, $notaId);
            if ($pdf === '') {
                json_response(['message' => 'No se pudo generar el PDF de la Nota de Traslado desde la API.'], 500, $config);
                return;
            }
        }
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

    if ($method === 'POST' && preg_match('#^/notas-traslado/([^/]+)/recibido$#', $path, $m)) {
        handle_notas_traslado_recibido($pdo, $config, $m[1]);
        return true;
    }

    if ($method === 'POST' && preg_match('#^/notas-traslado/([^/]+)/aprobar$#', $path, $m)) {
        handle_notas_traslado_aprobacion($pdo, $config, $m[1], true);
        return true;
    }

    if ($method === 'POST' && preg_match('#^/notas-traslado/([^/]+)/regenerar-pdf$#', $path, $m)) {
        handle_notas_traslado_regenerar_pdf($pdo, $config, $m[1]);
        return true;
    }

    if ($method === 'POST' && preg_match('#^/notas-traslado/([^/]+)/rechazar$#', $path, $m)) {
        handle_notas_traslado_aprobacion($pdo, $config, $m[1], false);
        return true;
    }

    if ($method === 'GET' && preg_match('#^/notas-traslado/([^/]+)/documento$#', $path, $m)) {
        handle_notas_traslado_documento($pdo, $config, $m[1]);
        return true;
    }

    return false;
}
