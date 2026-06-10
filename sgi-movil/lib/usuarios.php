<?php
declare(strict_types=1);

/** @return list<array{id: string, nombre: string, apellido: string, mail: string, areas: list<array{id: string, nombre: string}>, roles: list<string>}> */
function usuarios_listar_activos(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT u.Id, u.Nombre, u.Apellido, u.Mail, a.Id AS AreaId, a.Nombre AS AreaNombre
         FROM Usuarios u
         LEFT JOIN AreaUsuario au ON au.UsuariosId = u.Id
         LEFT JOIN Area a ON a.Id = au.AreasId AND a.Estado = 1
         WHERE u.Estado = 1
         ORDER BY u.Apellido, u.Nombre, a.Nombre'
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $usuarios = [];
    foreach ($rows as $row) {
        $id = (string) $row['Id'];
        if (!isset($usuarios[$id])) {
            $usuarios[$id] = [
                'id' => $id,
                'nombre' => (string) $row['Nombre'],
                'apellido' => (string) $row['Apellido'],
                'mail' => (string) $row['Mail'],
                'areas' => [],
                'roles' => [],
            ];
        }

        $areaId = trim((string) ($row['AreaId'] ?? ''));
        if ($areaId === '') {
            continue;
        }

        $usuarios[$id]['areas'][] = [
            'id' => $areaId,
            'nombre' => (string) $row['AreaNombre'],
        ];
    }

    $stmtRoles = $pdo->query(
        'SELECT ru.UsuariosId, r.RolName
         FROM RolUsuario ru
         INNER JOIN Roles r ON r.Id = ru.RolesId
         INNER JOIN Usuarios u ON u.Id = ru.UsuariosId AND u.Estado = 1
         ORDER BY r.RolName'
    );
    foreach ($stmtRoles->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (string) $row['UsuariosId'];
        if (!isset($usuarios[$id])) {
            continue;
        }

        $usuarios[$id]['roles'][] = (string) $row['RolName'];
    }

    return array_values($usuarios);
}
