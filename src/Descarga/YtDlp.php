<?php
declare(strict_types=1);

namespace App\Descarga;

use App\Config;

/**
 * Envoltorio seguro de yt-dlp: proc_open con args separados (nunca shell string),
 * lectura por stream_select con timeouts y mapeo de errores a mensajesusuarios.
 */
final class YtDlp
{
    public function __construct(private Config $config)
    {
    }

    /** @return array{ok:bool, codigo?:string, mensaje?:string, datos?:array<string,mixed>} */
    public function metadatos(string $url, Plataforma $plataforma): array
    {
        $args = [
            '-J', '--no-playlist', '--no-warnings', '--no-progress',
            '--skip-download', '--socket-timeout', '10', '--retries', '2',
        ];
        if (($cookies = $this->cookieArgs()) !== []) {
            array_push($args, ...$cookies);
        }
        $args[] = $url;

        $resultado = $this->ejecutar($args, (int)($this->config->get('validateTimeout', 25)));

        if (!$resultado['ok']) {
            return ['ok' => false, 'codigo' => $resultado['codigo'], 'mensaje' => $resultado['mensaje']];
        }

        $json = json_decode($resultado['datos'] ?? '', true);
        if (!is_array($json)) {
            return ['ok' => false, 'codigo' => ExcepcionValidacion::CODIGO['extraccion'],
                'mensaje' => 'No se pudieron obtener los datos del video. Comprueba que el enlace apunte a un video público.'];
        }

        $m = self::normalizarMeta($json);
        return ['ok' => true, 'datos' => $m];
    }

    /** Selector de formatos: prefiere MP4 progresivo (H.264+AAC), luego pistas separadas. */
    public function selectorFormatos(): string
    {
        return 'b[ext=mp4][vcodec^=avc][acodec^=mp4a]/b[ext=mp4]'
             . '/bv*[ext=mp4][vcodec^=avc]+ba[ext=m4a]/bv*+ba';
    }

    /**
     * Args base para el proceso de transmisión. Nunca automático -o - sin validar URL antes.
     * @return list<string>
     */
    public function argsStream(string $url): array
    {
        $args = [
            '-o', '-', '--no-playlist', '--no-warnings', '--no-progress', '--no-part',
            '--restrict-filenames', '--no-mtime',
            // fusión a Matroska por el pipe; en modo directo no se une nada
            '--merge-output-format', 'mkv',
            '-f', $this->selectorFormatos(),
        ];
        if (($cookies = $this->cookieArgs()) !== []) {
            array_push($args, ...$cookies);
        }
        $args[] = $url;
        return $args;
    }

    /** @return list<string> */
    private function cookieArgs(): array
    {
        $ruta = $this->config->get('cookieFile');
        if (is_string($ruta) && $ruta !== '' && is_file($ruta) && is_readable($ruta)) {
            return ['--cookies', $ruta];
        }
        return [];
    }

    /**
     * Ejecuta yt-dlp leyendo stdout/stderr con select y límite de tiempo.
     * @param list<string> $args
     * @return array{ok:bool, codigo?:string, mensaje?:string, datos?:string}
     */
    public function ejecutar(array $args, int $segundos): array
    {
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['yt-dlp', ...$args], $desc, $pipes, '/tmp', ['LC_ALL' => 'C.UTF-8']);
        if (!is_resource($proc)) {
            return ['ok' => false, 'codigo' => ExcepcionValidacion::CODIGO['extraccion'],
                'mensaje' => 'No se pudo iniciar el proceso de descarga.'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        fclose($pipes[0]);

        $salida = '';
        $stderr = '';
        $fin = microtime(true) + $segundos;
        try {
            while (true) {
                $resta = $fin - microtime(true);
                if ($resta <= 0) {
                    $this->matar($proc);
                    return ['ok' => false, 'codigo' => ExcepcionValidacion::CODIGO['timeout'],
                        'mensaje' => 'La búsqueda del video tardó demasiado. Inténtalo de nuevo.'];
                }
                $lectura = [$pipes[1], $pipes[2]];
                $escritura = null;
                $excp = null;
                $cant = stream_select($lectura, $escritura, $excp, 1, 500000);
                if ($cant === false) {
                    break;
                }
                foreach ($lectura as $fh) {
                    $parte = (string)fread($fh, 65536);
                    if ($fh === $pipes[1]) {
                        $salida .= $parte;
                    } else {
                        $stderr .= $parte;
                    }
                    if (strlen($salida) > 8 * 1024 * 1024) {
                        $this->matar($proc);
                        return ['ok' => false, 'codigo' => ExcepcionValidacion::CODIGO['extraccion'],
                            'mensaje' => 'El video es demasiado grande para analizar.'];
                    }
                }
                $estado = proc_get_status($proc);
                if (!$estado['running']) {
                    // Drena lo que quede
                    while (($parte = fread($pipes[1], 65536)) !== '' && $parte !== false) {
                        $salida .= $parte;
                    }
                    while (($parte = fread($pipes[2], 65536)) !== '' && $parte !== false) {
                        $stderr .= $parte;
                    }
                    break;
                }
            }
        } finally {
            $this->matar($proc);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }

        // stdout vacío = fallo de extracción: interpretar error de yt-dlp
        if ($salida === '' || json_decode($salida, true) === null) {
            return $this->interpretarError($stderr, $salida);
        }
        return ['ok' => true, 'datos' => $salida];
    }

    /** @param array<string,mixed> $json salida de yt-dlp -J */
    private static function normalizarMeta(array $json): array
    {
        $titulo = (string)($json['title'] ?? 'video');
        $duracion = isset($json['duration']) ? (int)round((float)$json['duration']) : null;

        $modo = 'fusion';
        $tam = null;
        $ext = (string)($json['ext'] ?? '');
        $vcodec = (string)($json['vcodec'] ?? 'none');
        $acodec = (string)($json['acodec'] ?? 'none');
        if ($ext === 'mp4' && $vcodec !== 'none' && $acodec !== 'none'
            && str_starts_with($vcodec, 'avc') && str_starts_with($acodec, 'mp4a')) {
            $modo = 'directo';
            $tam = isset($json['filesize']) ? (int)$json['filesize'] : null;
        }
        if ($tam === null && isset($json['filesize_approx'])) {
            $tam = (int)$json['filesize_approx'];
        }

        return [
            'titulo' => $titulo,
            'duracion' => $duracion,
            'tam' => $tam,
            'modo' => $modo,
        ];
    }

    /** Mapea el texto de yt-dlp a un mensaje claro. */
    private function interpretarError(string $stderr, string $salida): array
    {
        $txt = trim($stderr) !== '' ? $stderr : $salida;
        $linea = '';
        foreach (array_reverse(explode("\n", $txt)) as $l) {
            $l = trim($l);
            if ($l !== '' && !str_starts_with($l, 'WARNING')) {
                $linea = $l;
                break;
            }
        }

        return match (true) {
            str_contains($txt, 'login required'),
            str_contains($txt, 'rate-limit'),
            str_contains($txt, 'Sign in to confirm'),
            str_contains($txt, 'requested content is not available') => ['ok' => false,
                'codigo' => ExcepcionValidacion::CODIGO['plataforma_bloqueo'],
                'mensaje' => 'La plataforma exige iniciar sesión o ese contenido es privado. Solo se pueden descargar videos públicos y disponibles.'],
            str_contains($txt, 'is not a valid URL') => ['ok' => false,
                'codigo' => ExcepcionValidacion::CODIGO['url_invalida'],
                'mensaje' => 'El enlace no es válido. Copia la URL del video completo.'],
            str_contains($txt, 'Private video') => ['ok' => false,
                'codigo' => ExcepcionValidacion::CODIGO['sin_video'],
                'mensaje' => 'El video es privado y no se puede descargar.'],
            str_contains($txt, 'Video unavailable'),
            str_contains($txt, 'not available') => ['ok' => false,
                'codigo' => ExcepcionValidacion::CODIGO['sin_video'],
                'mensaje' => 'El video no está disponible (quizá fue eliminado o es privado).'],
            str_contains($txt, 'network'), str_contains($txt, 'Temporary failure') => ['ok' => false,
                'codigo' => ExcepcionValidacion::CODIGO['timeout'],
                'mensaje' => 'Hay un problema de red al contactar la plataforma. Inténtalo en unos segundos.'],
            default => ['ok' => false, 'codigo' => ExcepcionValidacion::CODIGO['extraccion'],
                'mensaje' => 'No se pudo procesar ese enlace.' . ($linea !== '' ? ' (detalle: ' . mb_substr($linea, 0, 120) . ')' : '')],
        };
    }

    /** Termina el proceso con SIGTERM y SIGKILL si no responde. */
    private function matar($proc): void
    {
        if (!is_resource($proc)) {
            return;
        }
        $estado = proc_get_status($proc);
        if ($estado['running']) {
            @proc_terminate($proc, 15);
            usleep(400000);
            $estado = proc_get_status($proc);
            if (is_resource($proc) && $estado['running']) {
                @proc_terminate($proc, 9);
            }
        }
    }
}
