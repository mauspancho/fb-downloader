<?php
declare(strict_types=1);

namespace App;

/** Referencia segura a los datos de una validación reciente, guardados en sesión. */
final class SesionDescargas
{
    private const CLAVE = 'fbdl_desc';
    private const VIGENCIA = 600; // 10 minutos

    /** Guarda los metadatos normalizados y devuelve el id de referencia. */
    public static function guardar(array $datos): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('Sesión no iniciada');
        }
        $id = bin2hex(random_bytes(16));
        $_SESSION[self::CLAVE][$id] = ['t' => time()] + $datos;
        self::podar();
        return $id;
    }

    /** Recupera y elimina un registro por id (null si no existe o expiró). */
    public static function tomar(string $id): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        $datos = $_SESSION[self::CLAVE][$id] ?? null;
        if (is_array($datos) && time() - (int)$datos['t'] <= self::VIGENCIA) {
            return $datos;
        }
        return null;
    }

    private static function podar(): void
    {
        if (!isset($_SESSION[self::CLAVE]) || !is_array($_SESSION[self::CLAVE])) {
            return;
        }
        foreach ($_SESSION[self::CLAVE] as $id => $datos) {
            if (time() - (int)($datos['t'] ?? 0) > self::VIGENCIA) {
                unset($_SESSION[self::CLAVE][$id]);
            }
        }
    }
}
