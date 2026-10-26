<?php
declare(strict_types=1);

namespace App;

final class Config
{
    /** @var array<string,mixed> */
    private array $valores;

    /** @param array<string,mixed> $valores */
    private function __construct(array $valores)
    {
        $this->valores = $valores;
    }

    public static function cargar(): self
    {
        $dir = APP_CONFIG_DIR;
        $base = is_file($dir . '/app.dist.php') ? self::leer($dir . '/app.dist.php') : [];
        $local = is_file($dir . '/app.php') ? self::leer($dir . '/app.php') : [];
        return new self(array_merge($base, $local));
    }

    private static function leer(string $ruta): array
    {
        $datos = require $ruta;
        return is_array($datos) ? $datos : [];
    }

    public function get(string $clave, mixed $defecto = null): mixed
    {
        return $this->valores[$clave] ?? $defecto;
    }
}
