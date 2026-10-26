<?php
declare(strict_types=1);

namespace App\Descarga;

use App\Config;

/**
 * Transmite el video al navegador en posterioridad de validación, sin
 * escribir el archivo en el servidor: pipeline de yt-dlp (y FFmpeg en modo
 * 'fusion') por pipes, con drenado en chunks hacia php://output.
 *
 * Modo 'directo': formato único MP4 progresivo -> bytes tal cual al cliente.
 * Modo 'fusion': pistas separadas -> yt-dlp une a Matroska en el pipe y
 *                FFmpeg re-mux a MP4 fragmentado (H.264/AAC), en línea.
 *
 * No llena la memoria PHP (chunks de 64 KiB) y nunca entrega un HTML
 * de error como si fuera video: si yt-dlp falla antes del primer byte,
 * falla como error de página (cabeceras no enviadas).
 */
final class Transmisor
{
    private const CHUNK = 65536;
    private const SEG_STALL = 60;

    private $proc = null;
    /** @var array<int,mixed> */
    private array $pipes = [];
    private bool $cabecerasEnviadas = false;
    private string $nombreUtf8 = '';
    private string $nombreAscii = '';
    private int $bytesEnviados = 0;

    /** @param mixed $fh lock de la ranura para liberar en shutdown */
    public function __construct(
        private Config $config,
        private string $url,
        private array $meta,
        private Plataforma $plataforma,
        private mixed $fh,
    ) {
        register_shutdown_function([$this, 'cerrar']);
    }

    /**
     * Abre procesos y recoge los primeros bytes (agenda de errores).
     * @return array{ok:bool, mensaje?:string, codigoHttp?:int}
     */
    public function iniciar(): array
    {
        $yt = new YtDlp($this->config);
        $args = $yt->argsStream($this->url);

        $fusion = ($this->meta['modo'] ?? 'fusion') !== 'directo';
        if ($fusion) {
            $frontend = implode(' ', array_map('escapeshellarg', ['yt-dlp', ...$args]));
            $remux = implode(' ', array_map('escapeshellarg', [
                'ffmpeg', '-nostdin', '-v', 'error',
                '-i', 'pipe:0', '-c', 'copy',
                '-movflags', '+frag_keyframe+empty_moov+default_base_moof',
                '-f', 'mp4', 'pipe:1',
            ]));
            $comando = ['setsid', 'sh', '-c', $frontend . ' | ' . $remux];
        } else {
            $comando = ['setsid', 'yt-dlp', ...$args];
        }

        $proc = @proc_open($comando, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $this->pipes, '/tmp', ['LC_ALL' => 'C.UTF-8']);

        if (!is_resource($proc)) {
            return ['ok' => false, 'mensaje' => 'No se pudo iniciar la descarga.'];
        }
        $this->proc = $proc;
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
        fclose($this->pipes[0]); // stdin no se usa

        [$this->nombreUtf8, $this->nombreAscii] = $this->nombreArchivo(
            (string)($this->meta['titulo'] ?? 'video'),
            $this->plataforma->sufijoArchivo()
        );

        // "Cebado": esperar los primeros bytes para poder exhibir error si
        // el proceso falla inmediatamente (y no después de los headers).
        $espera = min(20, (int)max(8, $this->config->get('validateTimeout', 25)));
        $fin = microtime(true) + $espera;
        $buf = '';
        $stderr = '';
        while (microtime(true) < $fin) {
            $lectura = [$this->pipes[1], $this->pipes[2]];
            $otro = null; $excp = null;
            $cant = @stream_select($lectura, $otro, $excp, 1, 250000);
            if ($cant === false) {
                break;
            }
            foreach ($lectura as $fhPipe) {
                $parte = @fread($fhPipe, self::CHUNK);
                if ($parte !== '' && $parte !== false) {
                    if ($fhPipe === $this->pipes[1]) {
                        $buf .= $parte;
                    } else {
                        $stderr .= $parte;
                    }
                }
            }
            if (strlen($buf) >= self::CHUNK) {
                break;
            }
            $estado = proc_get_status($this->proc);
            if (!$estado['running']) {
                // drena lo restante
                while (($parte = fread($this->pipes[1], self::CHUNK)) !== '' && $parte !== false) {
                    $buf .= $parte;
                }
                break;
            }
        }

        if (trim($buf) === '') {
            $msg = $this->procesoFalloMensaje($stderr);
            return ['ok' => false, 'mensaje' => $msg, 'codigoHttp' => 502];
        }
        $this->primeros = $buf;
        return ['ok' => true];
    }

    private string $primeros = '';

    /** Cabeceras de descrada de la descarga al navegador. */
    public function enviarCabeceras(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @apache_setenv('no-gzip', '1');
        @ini_set('zlib.output_compression', '0');
        @set_time_limit(0);
        ignore_user_abort(true);

        header('Content-Type: video/mp4');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . $this->nombreAscii . '"; filename*=UTF-8\'\'' . rawurlencode($this->nombreUtf8));

        // En modo directo y con tamaño conferido podemos dar Content-Length:
        // el archivo a la salida es exactamente el formato elegido.
        $tam = $this->meta['tam'] ?? null;
        if (($this->meta['modo'] ?? 'fusion') === 'directo' && is_int($tam) && $tam > 0 && $tam < (1 << 30)) {
            header('Content-Length: ' . $tam);
        }

        $this->cabecerasEnviadas = true;
        echo $this->primeros;
        $this->bytesEnviados = strlen($this->primeros);
        $this->primeros = '';
        flush();
    }

    /** Bucle principal de drenado: proc -> cliente, con watchdog. */
    public function drenar(): void
    {
        $inicio = microtime(true);
        $ultimoDato = microtime(true);
        $limiteTotal = (int)max(60, $this->config->get('streamTimeout', 600));

        while (true) {
            if (connection_aborted()) {
                error_log('[stream] cliente canceló tras ' . $this->bytesEnviados . ' bytes');
                return;
            }

            // Watchdogs
            if (microtime(true) - $ultimoDato > self::SEG_STALL) {
                error_log('[stream] stall >' . self::SEG_STALL . 's tras ' . $this->bytesEnviados . ' bytes; abortando');
                return;
            }
            if (microtime(true) - $inicio > $limiteTotal) {
                error_log('[stream] tiempo total vencido (' . $limiteTotal . "s) tras {$this->bytesEnviados} bytes");
                return;
            }

            $lectura = [$this->pipes[1], $this->pipes[2]];
            $otro = null; $excp = null;
            $cant = @stream_select($lectura, $otro, $excp, 1, 250000);
            if ($cant === false) {
                error_log('[stream] stream_select error');
                return;
            }

            foreach ($lectura as $fhPipe) {
                $parte = @fread($fhPipe, self::CHUNK);
                if ($parte === '' || $parte === false) {
                    continue;
                }
                if ($fhPipe === $this->pipes[1]) {
                    $ultimoDato = microtime(true);
                    echo $parte;
                    $this->bytesEnviados += strlen($parte);
                    @flush();
                    if (connection_aborted()) {
                        error_log('[stream] cliente canceló tras ' . $this->bytesEnviados . ' bytes');
                        return;
                    }
                } else {
                    if (str_contains($parte, 'Error') || str_contains($parte, 'error') || str_contains($parte, 'Invalid')) {
                        error_log('[stream][stderr] ' . trim($parte));
                    }
                }
            }

            $estado = proc_get_status($this->proc);
            if (!$estado['running']) {
                while (($parte = fread($this->pipes[1], self::CHUNK)) !== '' && $parte !== false) {
                    echo $parte;
                    $this->bytesEnviados += strlen($parte);
                }
                @flush();
                break;
            }
        }

        error_log('[stream] finalizado: ' . $this->bytesEnviados . ' bytes, ' 
            . (int)round(microtime(true) - $inicio) . 's');
    }

    /** Cierre garantizado (shutdown): mata el grupo de procesos y libera ranura. */
    public function cerrar(): void
    {
        if (is_resource($this->proc)) {
            $pid = (int)(proc_get_status($this->proc)['pid'] ?? 0);
            $this->matarGrupo($pid);
            fclose($this->pipes[1]);
            fclose($this->pipes[2]);
            proc_close($this->proc);
            $this->proc = null;
        }
        if (is_resource($this->fh)) {
            @flock($this->fh, LOCK_UN);
        }
    }

    private function matarGrupo(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }
        $estado = proc_get_status($this->proc);
        if (!$estado['running']) {
            return;
        }
        $pgid = function_exists('posix_getpgid') ? @posix_getpgid($pid) : null;
        if ($pgid) {
            if (function_exists('posix_kill')) {
                @posix_kill(-$pgid, 9);
            }
        } else {
            @proc_terminate($this->proc, 9);
        }
    }

    private function procesoFalloMensaje(string $stderr): string
    {
        $t = trim($stderr);
        if ($t === '') {
            return 'El video no se pudo procesar en este momento. Inténtalo de nuevo.';
        }
        if (str_contains($t, 'login required') || str_contains($t, 'rate-limit')
            || str_contains($t, 'Sign in to confirm') || str_contains($t, 'Requested content is not available')) {
            return 'La plataforma exige iniciar sesión o el contenido es privado. Solo videos públicos disponibles.';
        }
        if (str_contains($t, 'Private video') || str_contains($t, 'not available')) {
            return 'El video no está disponible (eliminado o privado).';
        }
        if (str_contains($t, 'network') || str_contains($t, 'Temporary failure')) {
            return 'Problema de red al contactar la plataforma. Inténtalo en unos segundos.';
        }
        $linea = explode("\n", $t)[0] ?? '';
        return 'No se pudo descargar el video' . ($linea !== '' ? ' (' . mb_substr(trim($linea), 0, 120) . ')' : '.');
    }

    /** @return array{0:string,1:string} [nombreUtf8, nombreAscii] */
    private function nombreArchivo(string $titulo, string $sufijoPlataforma): array
    {
        $titulo = str_replace(["\n", "\r", "\t"], ' ', $titulo);
        $titulo = preg_replace('/[\/\\\\:*?"<>|]/u', '-', $titulo) ?? '';
        $titulo = preg_replace('/\s+/u', ' ', trim($titulo)) ?? '';
        $titulo = mb_substr($titulo, 0, 80);
        if ($titulo === '') {
            $titulo = 'video';
        }
        $marcado = '-' . $sufijoPlataforma . '-' . date('Ymd-His') . '.mp4';
        $utf8 = $titulo . $marcado;

        if (function_exists('iconv')) {
            $asciiBase = @iconv('UTF-8', 'ASCII//TRANSLIT', $titulo);
            $asciiBase = $asciiBase === false ? $titulo : $asciiBase;
        } else {
            $asciiBase = $titulo;
        }
        $asciiBase = preg_replace('/[^A-Za-z0-9._ \-]/', '', $asciiBase) ?? '';
        $asciiBase = trim(preg_replace('/\s+/', ' ', $asciiBase) ?? '');
        if ($asciiBase === 'video') {
            $asciiBase = 'video';
        }

        return [$utf8, ($asciiBase ?: 'video') . $marcado];
    }
}
