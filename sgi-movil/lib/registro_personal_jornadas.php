<?php
declare(strict_types=1);

/** Fecha base segun horario habitual; la agrupacion tambien contempla cambios de turno reales. */
function registro_personal_fecha_laboral(DateTimeImmutable $fecha, ?string $ingreso, ?string $salida): string
{
    if ($ingreso !== null && $salida !== null) {
        $entradaMin = registro_personal_minutos_hora($ingreso);
        $salidaMin = registro_personal_minutos_hora($salida);
        if ($salidaMin <= $entradaMin
            && registro_personal_minutos_hora($fecha->format('H:i:s')) <= min($salidaMin + 240, $entradaMin)) {
            return $fecha->modify('-1 day')->format('Y-m-d');
        }
    }
    return $fecha->format('Y-m-d');
}

function registro_personal_minutos_hora(string $hora): float
{
    $p = array_map('intval', explode(':', $hora));
    return $p[0] * 60 + ($p[1] ?? 0) + ($p[2] ?? 0) / 60;
}

/** Agrupa marcas reales y manuales con la misma regla, sin modificar su fecha ni procedencia. */
function registro_personal_agrupar_jornadas(array $fichadas, ?string $ingreso, ?string $salida): array
{
    usort($fichadas, static fn(array $a, array $b): int => $a['Fecha'] <=> $b['Fecha']);
    $dias = [];
    foreach ($fichadas as $fichada) {
        $fecha = $fichada['Fecha'];
        $clave = registro_personal_fecha_laboral($fecha, $ingreso, $salida);
        $anterior = $fecha->modify('-1 day')->format('Y-m-d');
        $grupo = $dias[$anterior] ?? [];
        if ($clave === $fecha->format('Y-m-d') && $grupo !== [] && (int) $fecha->format('G') < 12) {
            $primera = $grupo[0]['Fecha'];
            $ultima = $grupo[count($grupo) - 1]['Fecha'];
            $minutos = ($fecha->getTimestamp() - $primera->getTimestamp()) / 60;
            $entradaDuplicada = ($ultima->getTimestamp() - $primera->getTimestamp()) / 60 <= 2;
            $horaEntrada = $ingreso !== null ? registro_personal_minutos_hora($ingreso) : 360;
            $cercaDeNuevaEntrada = abs(registro_personal_minutos_hora($fecha->format('H:i:s')) - $horaEntrada) <= 60;
            // Solo cerrar una entrada pendiente dentro de la duracion maxima de jornada.
            if ($entradaDuplicada && $minutos >= 5 && $minutos <= 13 * 60 && !$cercaDeNuevaEntrada) {
                $clave = $anterior;
            }
        }
        $dias[$clave][] = $fichada;
    }
    return $dias;
}
