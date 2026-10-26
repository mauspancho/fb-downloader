<?php
declare(strict_types=1);

namespace App\Descarga;

/** Límites de concurrencia y de uso por IP, mediante archivos + flock en /tmp. */
final class Limites
{
    private const DIR_SLOTS = '/tmp/fbdl-slots';
    private const DIR_RATE  = '/tmp/fbdl-rate';

    /**
     * Intenta tomar una de $max ranuras en $nombre (ej. "stream", "meta").
     * Devuelve el handle del archivo con el lock activo, o null si no hay ranura.
     */
    public static function slot(string $nombre, int $max): mixed
    {
        if (!is_dir(self::DIR_SLOTS)) {
            @mkdir(self::DIR_SLOTS, 0700, true);
        }
        for ($i = 0; $i < $max; $i++) {
            $ruta = self::DIR_SLOTS . '/' . $nombre . '-' . $i . '.lock';
            $fh = @fopen($ruta, 'c');
            if ($fh === false) {
                continue;
            }
            if (@flock($fh, LOCK_EX | LOCK_NB)) {
                return $fh;
            }
            fclose($fh);
        }
        return null;
    }

    /** Libera la ranura al terminar la petición. */
    public static function liberar(mixed $fh): void
    {
        if (is_resource($fh)) {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * Ventana deslizante simple por IP. Devuelve true si permitido.
     * $ventana en segundos, $max peticiones por ventana.
     */
    public static function tasaPorIp(string $nombre, string $ip, int $max, int $ventana): bool
    {
        if (!is_dir(self::DIR_RATE)) {
            @mkdir(self::DIR_RATE, 0700, true);
        }
        $ruta = self::DIR_RATE . '/' . $nombre . '-' . hash('sha256', $ip) . '.rate';
        $fh = @fopen($ruta, 'c+');
        if ($fh === false) {
            return true; // si /tmp falla, no bloqueamos legit users por ese motivo
        }
        $permitido = true;
        $ahora = time();
        if (@flock($fh, LOCK_EX)) {
            $contenido = stream_get_contents($fh) ?: '';
            [$marca, $conteo] = array_pad(explode(':', $contenido, 2), 2, null);
            $marca = (int)$marca;
            $conteo = (int)$conteo;
            if ($marca + $ventana < $ahora) {
                $marca = $ahora;
                $conteo = 0;
            }
            if ($conteo >= $max) {
                $permitido = false;
            } else {
                $conteo++;
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, $marca . ':' . $conteo);
            @flock($fh, LOCK_UN);
        }
        fclose($fh);
        return $permitido;
    }
}
