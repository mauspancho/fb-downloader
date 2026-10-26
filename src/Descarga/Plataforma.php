<?php
declare(strict_types=1);

namespace App\Descarga;

/** Plataforma base: lista de hosts permitidos (whitelist) y etiquetas. */
abstract class Plataforma
{
    abstract public function hosts(): array;

    abstract public function codigo(): string;

    abstract public function nombre(): string;

    /** Sufijo del nombre de archivo descargado. */
    public function sufijoArchivo(): string
    {
        return $this->codigo();
    }

    public function acepta(string $host): bool
    {
        return in_array(strtolower($host), $this->hosts(), true);
    }
}
