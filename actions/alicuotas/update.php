<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/_inc/credenciales.php';
require_once dirname(__DIR__, 2) . '/_inc/api_tokens.php';

function responder(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pdo_connect(string $servidor, string $baseDeDatos, string $usuario, string $contrasena): PDO
{
    $dsn = "mysql:host={$servidor};dbname={$baseDeDatos};charset=utf8mb4";
    return new PDO($dsn, $usuario, $contrasena, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function normalizar_cuit(?string $cuit): string
{
    return preg_replace('/\D+/', '', (string) $cuit) ?? '';
}

function decimal_o_null($value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    $normalized = str_replace(',', '.', (string) $value);
    return is_numeric($normalized) ? (float) $normalized : null;
}

function env_o_null(string $key): ?string
{
    $value = getenv($key);
    return is_string($value) && trim($value) !== '' ? trim($value) : null;
}

function fecha_vigencia_desde(): string
{
    return (string) ($_POST['vigenciaDesde'] ?? $_GET['vigenciaDesde'] ?? date('Y-m-01'));
}

function fecha_vigencia_hasta(): ?string
{
    return $_POST['vigenciaHasta'] ?? $_GET['vigenciaHasta'] ?? null;
}

function http_json_get(string $url, array $query = [], array $headers = [], int $timeout = 25): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $finalUrl = $query ? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query) : $url;
    $curl = curl_init($finalUrl);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => array_values(array_filter($headers)),
    ]);

    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if (!is_string($body) || $status < 200 || $status >= 300) {
        return null;
    }

    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

function valor_decimal_por_claves(array $data, array $keys): ?float
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            $value = decimal_o_null($data[$key]);
            if ($value !== null) {
                return $value;
            }
        }
    }

    foreach ($data as $value) {
        if (is_array($value)) {
            $nested = valor_decimal_por_claves($value, $keys);
            if ($nested !== null) {
                return $nested;
            }
        }
    }

    return null;
}

function consultar_alicuota_iibb_arba(string $cuit): array
{
    $endpoint = env_o_null('ARBA_ALICUOTAS_ENDPOINT');
    if ($endpoint === null) {
        return ['alicuota' => null, 'detalle' => 'ARBA_ALICUOTAS_ENDPOINT no configurado.'];
    }

    $headers = [];
    if ($token = env_o_null('ARBA_ALICUOTAS_TOKEN')) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($apiKey = env_o_null('ARBA_ALICUOTAS_API_KEY')) {
        $headers[] = 'X-API-KEY: ' . $apiKey;
    }

    $response = http_json_get($endpoint, [
        'cuit' => $cuit,
        'desde' => fecha_vigencia_desde(),
        'hasta' => fecha_vigencia_hasta() ?? date('Y-m-t'),
    ], $headers);

    if ($response === null) {
        return ['alicuota' => null, 'detalle' => 'ARBA no devolvió una respuesta válida.'];
    }

    $alicuota = valor_decimal_por_claves($response, [
        'alicuota_retencion',
        'alicuotaRetencion',
        'retencion',
        'retencion_iibb',
        'retencionIibb',
        'alicuota',
    ]);

    return [
        'alicuota' => $alicuota,
        'detalle' => $alicuota === null ? 'ARBA respondió sin alícuota de retención.' : 'ARBA',
    ];
}

function consultar_alicuota_ganancias_afip(string $cuit): array
{
    $endpoint = env_o_null('AFIP_GANANCIAS_ENDPOINT');
    if ($endpoint === null) {
        return ['alicuota' => null, 'detalle' => 'AFIP_GANANCIAS_ENDPOINT no configurado.'];
    }

    $headers = [];
    if ($token = env_o_null('AFIP_GANANCIAS_TOKEN')) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($apiKey = env_o_null('AFIP_GANANCIAS_API_KEY')) {
        $headers[] = 'X-API-KEY: ' . $apiKey;
    }

    $response = http_json_get($endpoint, [
        'cuit' => $cuit,
        'desde' => fecha_vigencia_desde(),
        'hasta' => fecha_vigencia_hasta() ?? date('Y-m-t'),
    ], $headers);

    if ($response === null) {
        return ['alicuota' => null, 'detalle' => 'AFIP/ARCA no devolvió una respuesta válida.'];
    }

    $alicuota = valor_decimal_por_claves($response, [
        'alicuota_ganancias',
        'alicuotaGanancias',
        'ganancias',
        'retencion_ganancias',
        'retencionGanancias',
        'alicuota',
    ]);

    return [
        'alicuota' => $alicuota,
        'detalle' => $alicuota === null ? 'AFIP/ARCA respondió sin alícuota de ganancias.' : 'AFIP/ARCA',
    ];
}

function consultar_alicuotas(array $empresa): ?array
{
    $cuit = normalizar_cuit($empresa['Cuit'] ?? '');
    if ($cuit === '') {
        return null;
    }

    $iibbRaw = $_POST['alicuotaIibb'] ?? $_GET['alicuotaIibb'] ?? null;
    $gananciasRaw = $_POST['alicuotaGanancias'] ?? $_GET['alicuotaGanancias'] ?? null;
    $alicuotaIibb = decimal_o_null($iibbRaw);
    $alicuotaGanancias = decimal_o_null($gananciasRaw);
    $fuentes = [];
    $observaciones = [];

    if ($alicuotaIibb !== null) {
        $fuentes[] = 'Parámetro IIBB';
    }
    if ($alicuotaGanancias !== null) {
        $fuentes[] = 'Parámetro Ganancias';
    }

    if ($alicuotaIibb === null) {
        $arba = consultar_alicuota_iibb_arba($cuit);
        $alicuotaIibb = $arba['alicuota'];
        $fuentes[] = $arba['detalle'];
        if ($alicuotaIibb === null) {
            $observaciones[] = $arba['detalle'];
        }
    }

    if ($alicuotaGanancias === null) {
        $afip = consultar_alicuota_ganancias_afip($cuit);
        $alicuotaGanancias = $afip['alicuota'];
        $fuentes[] = $afip['detalle'];
        if ($alicuotaGanancias === null) {
            $observaciones[] = $afip['detalle'];
        }
    }

    if ($alicuotaIibb === null && ($default = env_o_null('SGI_ALICUOTA_IIBB_DEFAULT')) !== null) {
        $alicuotaIibb = decimal_o_null($default);
        $fuentes[] = 'Default IIBB';
    }

    if ($alicuotaGanancias === null && ($default = env_o_null('SGI_ALICUOTA_GANANCIAS_DEFAULT')) !== null) {
        $alicuotaGanancias = decimal_o_null($default);
        $fuentes[] = 'Default Ganancias';
    }

    if ($alicuotaIibb === null || $alicuotaGanancias === null) {
        return null;
    }

    return [
        'alicuota_iibb' => $alicuotaIibb,
        'alicuota_ganancias' => $alicuotaGanancias,
        'vigencia_desde' => fecha_vigencia_desde(),
        'vigencia_hasta' => fecha_vigencia_hasta(),
        'fuente' => substr(implode(' / ', array_unique(array_filter($fuentes))), 0, 100),
        'observaciones' => substr(
            $observaciones
                ? implode(' ', array_unique($observaciones))
                : 'Alícuotas obtenidas por CUIT y persistidas para uso del SGI.',
            0,
            500
        ),
    ];
}

function upsert_alicuota(PDO $pdo, array $empresa, array $alicuotas): void
{
    $id = guid();
    $sql = "
        INSERT INTO AlicuotasEmpresa
            (Id, EmpresaId, Cuit, AlicuotaGanancias, AlicuotaIibb, VigenciaDesde, VigenciaHasta, FechaConsulta, Fuente, Observaciones, Estado, DateUpdate)
        VALUES
            (:id, :empresaId, :cuit, :ganancias, :iibb, :desde, :hasta, NOW(6), :fuente, :observaciones, 1, NOW(6))
        ON DUPLICATE KEY UPDATE
            Cuit = VALUES(Cuit),
            AlicuotaGanancias = VALUES(AlicuotaGanancias),
            AlicuotaIibb = VALUES(AlicuotaIibb),
            VigenciaDesde = VALUES(VigenciaDesde),
            VigenciaHasta = VALUES(VigenciaHasta),
            FechaConsulta = VALUES(FechaConsulta),
            Fuente = VALUES(Fuente),
            Observaciones = VALUES(Observaciones),
            Estado = 1,
            DateUpdate = VALUES(DateUpdate)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $id,
        ':empresaId' => $empresa['Id'],
        ':cuit' => $empresa['Cuit'],
        ':ganancias' => $alicuotas['alicuota_ganancias'],
        ':iibb' => $alicuotas['alicuota_iibb'],
        ':desde' => $alicuotas['vigencia_desde'],
        ':hasta' => $alicuotas['vigencia_hasta'],
        ':fuente' => $alicuotas['fuente'],
        ':observaciones' => $alicuotas['observaciones'],
    ]);
}

function guid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

try {
    if (!sgi_validar_api_token()) {
        responder(401, ['success' => false, 'message' => 'Token inválido o ausente.']);
    }

    $pdo = pdo_connect($servidor, $baseDeDatos, $usuario, $contrasena);
    $modo = strtolower((string) ($_POST['modo'] ?? $_GET['modo'] ?? 'empresa'));
    $empresaId = trim((string) ($_POST['empresaId'] ?? $_GET['empresaId'] ?? ''));
    $cuit = normalizar_cuit($_POST['cuit'] ?? $_GET['cuit'] ?? '');

    if ($modo === 'todas') {
        $stmt = $pdo->query("SELECT Id, Nombre, Cuit FROM Empresa WHERE Estado = 1 AND TipoId IN (1, 2, 3)");
        $empresas = $stmt->fetchAll();
    } else {
        if ($empresaId === '' && $cuit === '') {
            responder(400, ['success' => false, 'message' => 'Debe informar empresaId o cuit.']);
        }

        $sql = "SELECT Id, Nombre, Cuit FROM Empresa WHERE Estado = 1";
        $params = [];
        if ($empresaId !== '') {
            $sql .= " AND Id = :empresaId";
            $params[':empresaId'] = $empresaId;
        } else {
            $sql .= " AND REPLACE(REPLACE(Cuit, '-', ''), ' ', '') = :cuit";
            $params[':cuit'] = $cuit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $empresas = $stmt->fetchAll();
    }

    $actualizadas = 0;
    $sinAlicuota = [];

    foreach ($empresas as $empresa) {
        $alicuotas = consultar_alicuotas($empresa);
        if ($alicuotas === null) {
            $sinAlicuota[] = [
                'empresaId' => $empresa['Id'],
                'cuit' => $empresa['Cuit'],
                'nombre' => $empresa['Nombre'] ?? '',
            ];
            continue;
        }

        upsert_alicuota($pdo, $empresa, $alicuotas);
        $actualizadas++;
    }

    responder(200, [
        'success' => $actualizadas > 0 && count($sinAlicuota) === 0,
        'actualizadas' => $actualizadas,
        'sinAlicuota' => $sinAlicuota,
        'message' => count($sinAlicuota) > 0
            ? 'No se pudieron obtener alícuotas para una o más empresas.'
            : 'Alícuotas actualizadas correctamente.',
    ]);
} catch (Throwable $e) {
    responder(500, ['success' => false, 'message' => $e->getMessage()]);
}
