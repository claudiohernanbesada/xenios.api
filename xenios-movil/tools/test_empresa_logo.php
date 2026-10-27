<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/lib/notas_traslado.php';

$checks = 0;
function logo_check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('Falló: ' . $label);
    $checks++;
}
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE Hoteles (Id INTEGER, Nombre TEXT, RazonSocial TEXT, IdentificacionFiscal TEXT, Direccion TEXT, LogoPdfJpeg BLOB)');
$pdo->exec('CREATE TABLE NotasTraslado (Id TEXT, EmpresaTenantId INTEGER)');
$pdo->exec("INSERT INTO Hoteles VALUES (1,'Empresa A','Empresa A','A','Dirección A',NULL),(2,'Empresa B','Empresa B','B','Dirección B',NULL)");
$pdo->exec("INSERT INTO NotasTraslado VALUES ('note-a',1),('note-b',2)");
$a = notas_traslado_marca_empresa($pdo, ['Id' => 'note-a', 'EmpresaTenantId' => 2]);
$b = notas_traslado_marca_empresa($pdo, ['Id' => 'note-b']);
logo_check($a['Nombre'] === 'Empresa A', 'empresa de la nota persistida');
logo_check($b['Nombre'] === 'Empresa B', 'empresa B');
logo_check($a['logo'] === null, 'empresa histórica sin imagen');
logo_check(notas_traslado_pdf_marca_marker($a) !== notas_traslado_pdf_marca_marker($b), 'caché separada por marca');
$updated = $a; $updated['logo'] = ['data' => 'updated'];
logo_check(notas_traslado_pdf_marca_marker($a) !== notas_traslado_pdf_marca_marker($updated), 'reemplazo invalida PDF');
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAADAAkDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDy2iiivUPNP//Z', true);
$update = $pdo->prepare('UPDATE Hoteles SET LogoPdfJpeg = ? WHERE Id = 1');
$update->bindValue(1, $jpeg, PDO::PARAM_LOB); $update->execute();
$a = notas_traslado_marca_empresa($pdo, ['Id' => 'note-a']);
logo_check($a['logo']['width'] === 9 && $a['logo']['height'] === 3, 'JPEG de empresa en proporción 3:1');
$note = ['Id' => '00000000-0000-4000-8000-000000000001', 'NumeroNota' => 'TEST-1', 'Fecha' => '2026-10-27', 'TipoEnvio' => 'interno'];
$pdf = notas_traslado_pdf_build($note, [], $a);
logo_check(str_starts_with($pdf, '%PDF-'), 'PDF generado');
logo_check(str_contains($pdf, 'Empresa A'), 'nombre de empresa en PDF');
logo_check(!str_contains($pdf, 'Empresa B'), 'no aparece otra empresa');
logo_check(str_contains($pdf, notas_traslado_pdf_marca_marker($a)), 'versión de marca persistida');
logo_check(str_contains($pdf, $jpeg), 'imagen de empresa embebida en PDF');
$pdo->exec("UPDATE Hoteles SET LogoPdfJpeg = '<svg>invalid</svg>' WHERE Id = 1");
$invalid = notas_traslado_marca_empresa($pdo, ['Id' => 'note-a']);
logo_check($invalid['logo'] === null, 'binario no JPEG rechazado');
echo "OK: {$checks} verificaciones de marca y aislamiento de PDF.\n";
