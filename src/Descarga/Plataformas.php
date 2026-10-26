<?php
declare(strict_types=1);

namespace App\Descarga;

final class Plataformas
{
    /** @return array<string,Plataforma> mapa código => plataforma */
    public static function todas(): array
    {
        static $mapa = null;
        if ($mapa === null) {
            $mapa = [
                'fb' => new Facebook(),
                'ig' => new Instagram(),
            ];
        }
        return $mapa;
    }

    public static function obtener(string $codigo): Plataforma
    {
        $p = self::todas()[$codigo] ?? null;
        if ($p === null) {
            throw new ExcepcionValidacion(
                'Plataforma desconocida.',
                ExcepcionValidacion::CODIGO['plataforma']
            );
        }
        return $p;
    }
}
