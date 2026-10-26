<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Descarga\ExcepcionValidacion;
use App\Config;
use App\Descarga\Limites;
use App\Descarga\Plataformas;
use App\Descarga\Url;
use App\Descarga\YtDlp;
use App\SesionDescargas;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

sesión_iniciar();

function respuesta(array $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    // Desconexión limpia
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}

// --- Solo POST estricto -----------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    respuesta(['ok' => false, 'codigo' => 'metodo', 'mensaje' => 'Método no permitido.'], 405);
}

// Verificación de origen si el navegador la envía
if (isset($_SERVER['HTTP_ORIGIN'])) {
    $origen = parse_url($_SERVER['HTTP_ORIGIN']);
    $esperadoHost = parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if (($origen['host'] ?? '') !== ($esperadoHost ?? '')) {
        respuesta(['ok' => false, 'codigo' => 'sesion', 'mensaje' => 'Solicitud proveniente de otro sitio.'], 403);
    }
}

// CSRF
if (empty($_SESSION['csrf']) || !isset($_POST['token'])
    || !hash_equals($_SESSION['csrf'], (string)$_POST['token'])) {
    respuesta(['ok' => false, 'codigo' => 'sesion', 'mensaje' => 'Recarga la página e inténtalo de nuevo.'], 403);
}

try {
    $config = Config::cargar();

    // Tasa por IP para el endpoint de análisis
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip !== '' && !Limites::tasaPorIp('meta', $ip, (int)$config->get('metaMaxPorMinuto', 8), 60)) {
        respuesta(['ok' => false, 'codigo' => 'limite', 'mensaje' => 'Demasiadas solicitudes en poco tiempo. Espera un momento.'], 429);
    }

    // Ranura de análisis (procesos yt-dlp costosos acotados)
    $fh = Limites::slot('meta', (int)$config->get('metaConcurrentes', 4));
    if ($fh === null) {
        respuesta(['ok' => false, 'codigo' => 'limite', 'mensaje' => 'El servicio está ocupado. Espera unos segundos.'], 503);
    }

    try {
        $plataforma = Plataformas::obtener((string)($_POST['p'] ?? ''));
        $url = Url::normalizar((string)($_POST['u'] ?? ''), $plataforma);

        $yt = new YtDlp($config);
        $resultado = $yt->metadatos($url, $plataforma);
        if (!$resultado['ok']) {
            respuesta(['ok' => false, 'codigo' => $resultado['codigo'], 'mensaje' => $resultado['mensaje']]);
        }

        $meta = $resultado['datos'];
        $id = SesionDescargas::guardar([
            'url' => $url,
            'pl' => $plataforma->codigo(),
            'titulo' => $meta['titulo'],
            'modo' => $meta['modo'],
            'tam' => $meta['tam'],
            'duracion' => $meta['duracion'],
        ]);

        respuesta([
            'ok' => true,
            'id' => $id,
            'titulo' => $meta['titulo'],
            'tam' => $meta['tam'],
            'tamAprox' => $meta['modo'] === 'fusion',
            'duracion' => $meta['duracion'],
        ]);
    } finally {
        Limites::liberar($fh);
    }
} catch (ExcepcionValidacion $e) {
    respuesta(['ok' => false, 'codigo' => $e->codigo, 'mensaje' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[validar] ' . $e->getMessage());
    respuesta(['ok' => false, 'codigo' => 'error_interno', 'mensaje' => 'Error interno. Inténtalo de nuevo.'], 500);
}
