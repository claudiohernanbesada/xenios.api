<?php
declare(strict_types=1);
require_once __DIR__ . '/security.php';

const XENIOS_DOCUMENTOS_MAX_BYTES = 25 * 1024 * 1024;
const XENIOS_DOCUMENTOS_REPOS = [
    'comprobantes' => ['env' => 'XENIOS_COMPROBANTES_DIR', 'dir' => '/var/storage/comprobantes'],
    'facturas' => ['env' => 'XENIOS_FACTURAS_DIR', 'dir' => '/var/storage/facturas'],
    'ordenPago' => ['env' => 'XENIOS_ORDEN_PAGO_DIR', 'dir' => '/var/storage/ordenPago'],
];
const XENIOS_DOCUMENTOS_MIMES = [
    'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
    'txt' => 'text/plain', 'csv' => 'text/csv', 'xml' => 'application/xml',
    'doc' => 'application/msword', 'xls' => 'application/vnd.ms-excel',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];

function xenios_documentos_error(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function xenios_documentos_empresa(): string
{
    xenios_require_service_key();
    $tenant = strtolower(str_replace('-', '', (string) ($_SERVER['HTTP_X_XENIOS_EMPRESA_ID'] ?? '')));
    if (!preg_match('/\A[0-9a-f]{32}\z/', $tenant) || $tenant === str_repeat('0', 32)) {
        xenios_documentos_error(400, 'Empresa requerida');
    }
    return $tenant;
}

function xenios_documentos_dir(string $repo): string
{
    if (!isset(XENIOS_DOCUMENTOS_REPOS[$repo])) {
        throw new InvalidArgumentException('Repositorio desconocido');
    }
    $options = XENIOS_DOCUMENTOS_REPOS[$repo];
    return rtrim(trim(getenv($options['env']) ?: $options['dir']), '/\\');
}

function xenios_documentos_validar(string $path, string $ext): bool
{
    if (!isset(XENIOS_DOCUMENTOS_MIMES[$ext])) return false;
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $head = (string) file_get_contents($path, false, null, 0, 512);
    if ($ext === 'pdf') return $mime === 'application/pdf' && str_starts_with($head, '%PDF-');
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $image = @getimagesize($path);
        return $mime === XENIOS_DOCUMENTOS_MIMES[$ext] && $image
            && $image['mime'] === $mime && $image[0] * $image[1] <= 20000000;
    }
    if (in_array($ext, ['docx', 'xlsx'], true)) {
        if (!str_starts_with($head, "PK\x03\x04") || !class_exists(ZipArchive::class)) return false;
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return false;
        try {
            return $zip->numFiles < 20000 && $zip->locateName('[Content_Types].xml') !== false
                && $zip->locateName($ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml') !== false;
        } finally { $zip->close(); }
    }
    if (in_array($ext, ['doc', 'xls'], true)) {
        return str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")
            && in_array($mime, ['application/msword', 'application/vnd.ms-excel', 'application/x-ole-storage', 'application/CDFV2'], true);
    }
    if ($ext === 'xml') {
        return in_array($mime, ['application/xml', 'text/xml', 'text/plain'], true)
            && preg_match('/\A\s*(?:<\?xml\s|<[a-zA-Z_])/', $head) === 1;
    }
    return in_array($ext, ['txt', 'csv'], true) && in_array($mime, ['text/plain', 'text/csv', 'application/csv'], true);
}

function xenios_documentos_guardar(string $repo): never
{
    $tenant = xenios_documentos_empresa();
    xenios_rate_limit('documentos-upload', 100, 60);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST'); xenios_documentos_error(405, 'Método no permitido');
    }
    $file = $_FILES['archivo'] ?? [];
    if (in_array($file['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > XENIOS_DOCUMENTOS_MAX_BYTES + 1024 * 1024) {
        xenios_documentos_error(413, 'El archivo supera los 25 MB');
    }
    $tmp = $file['tmp_name'] ?? null;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($tmp)
        || !is_string($file['name'] ?? null) || !is_uploaded_file($tmp)) {
        xenios_documentos_error(400, 'Enviar un documento en el campo archivo');
    }
    $size = filesize($tmp);
    if ($size === false || $size === 0 || $size > XENIOS_DOCUMENTOS_MAX_BYTES) {
        xenios_documentos_error($size > XENIOS_DOCUMENTOS_MAX_BYTES ? 413 : 400, 'Tamaño de archivo inválido');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!xenios_documentos_validar($tmp, $ext)) xenios_documentos_error(400, 'Formato o contenido de documento inválido');
    try {
        $dir = xenios_documentos_dir($repo);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Directorio no disponible');
        $prefix = rtrim(strtr(base64_encode(hex2bin($tenant)), '+/', '-_'), '=');
        $random = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $name = $prefix . '_' . $random . '.' . $ext;
        $path = $dir . '/' . $name;
        if (!move_uploaded_file($tmp, $path)) throw new RuntimeException('No se pudo guardar el documento');
        if (!chmod($path, 0640)) { unlink($path); throw new RuntimeException('No se pudieron aplicar permisos'); }
        http_response_code(201);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['archivo' => $name, 'tamano' => $size, 'tipo' => XENIOS_DOCUMENTOS_MIMES[$ext]], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $error) {
        error_log('[xenios-documentos] ' . $error->getMessage());
        xenios_documentos_error(500, 'No se pudo guardar el documento');
    }
}

function xenios_documentos_descargar(string $repo): never
{
    $tenant = xenios_documentos_empresa();
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD'); xenios_documentos_error(405, 'Método no permitido');
    }
    $name = $_GET['f'] ?? '';
    if (!is_string($name) || !preg_match('/\A([A-Za-z0-9_-]{22})_[A-Za-z0-9_-]{22}\.([a-z0-9]+)\z/', $name, $matches)
        || !isset(XENIOS_DOCUMENTOS_MIMES[$matches[2]])) xenios_documentos_error(400, 'Nombre de documento inválido');
    if ($matches[1] !== rtrim(strtr(base64_encode(hex2bin($tenant)), '+/', '-_'), '=')) xenios_documentos_error(404, 'Documento no encontrado');
    $dir = realpath(xenios_documentos_dir($repo));
    $path = $dir === false ? false : realpath($dir . '/' . $name);
    if ($path === false || dirname($path) !== $dir || is_link($dir . '/' . $name) || !is_file($path) || !is_readable($path)) {
        xenios_documentos_error(404, 'Documento no encontrado');
    }
    header('Content-Type: ' . XENIOS_DOCUMENTOS_MIMES[$matches[2]]);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: sandbox");
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') readfile($path);
    exit;
}
