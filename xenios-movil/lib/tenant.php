<?php
declare(strict_types=1);

/** Contexto por conexion: el hotel nunca se obtiene de filtros de pantalla. */
function xenios_tenant_contexts(): WeakMap
{
    static $contexts;
    return $contexts ??= new WeakMap();
}

function xenios_tenant_select(PDO $pdo, string $empresa): void
{
    $empresa = strtolower($empresa);
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $empresa)
        || $empresa === '00000000-0000-0000-0000-000000000000') {
        throw new InvalidArgumentException('Hotel invalido.');
    }
    $contexts = xenios_tenant_contexts();
    if (isset($contexts[$pdo]) && $contexts[$pdo] !== $empresa) {
        throw new RuntimeException('No puede cambiar el hotel de la operacion.');
    }
    $contexts[$pdo] = $empresa;
}

function xenios_tenant_sql(PDO $pdo, string $alias = ''): string
{
    $empresa = xenios_tenant_contexts()[$pdo] ?? null;
    if ($empresa === null) throw new RuntimeException('Se requiere un hotel para consultar datos.');
    if ($alias !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/D', $alias)) throw new InvalidArgumentException('Alias invalido.');
    return ($alias === '' ? '' : $alias . '.') . 'EmpresaTenantId = ' . $pdo->quote($empresa);
}

/** Los flujos de un empleado toman el hotel de su registro, no de su legajo. */
function xenios_tenant_for_personal(PDO $pdo, string $personalId): void
{
    if (isset(xenios_tenant_contexts()[$pdo])) return;
    $stmt = $pdo->prepare('SELECT EmpresaTenantId FROM Personal WHERE Id = ?');
    $stmt->execute([$personalId]);
    $empresa = $stmt->fetchColumn();
    if (!is_string($empresa)) throw new RuntimeException('Personal no disponible.');
    xenios_tenant_select($pdo, $empresa);
}
