<?php
declare(strict_types=1);

/** @return list<array{id: string, nombre: string}> */
function areas_listar_activas(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT Id, Nombre
         FROM Area
         WHERE Estado = 1
         ORDER BY Nombre'
    );

    return array_map(static function (array $row): array {
        return [
            'id' => (string) $row['Id'],
            'nombre' => (string) $row['Nombre'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}
