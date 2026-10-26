<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Descarga\ExcepcionValidacion;
use App\Config;
use App\Descarga\Limites;
use App\Descarga\Plataformas;
use App\Descarga\Transmisor;
use App\Descarga\Url;
use App\SesionDescargas;

/**
 * Punto de entrada de la transmisión.
 * POST: token (CSRF), id (referencia de la validación en sesión) —
 * la URL nunca se pasa por la petición de transmisión.
 *
 * Error: respuesta HTML en el iframe (mismo origen), nunca "video".
 */
function errorIframe(string $mensaje, int $codigoHttp = 400): never
{
    http_response_code($codigoHttp);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $msg = htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Error</title>'
       . '<link rel="stylesheet" href="/assets/css/main.css"></head>'
       . '<body class="error-iframe"><div class="alerta alerta--err" data-error="1">'
       . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div></body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    errorIframe('Método no permitido.', 405);
}

sesión_iniciar();

if (empty($_SESSION['csrf']) || !isset($_POST['token'])
    || !hash_equals($_SESSION['csrf'], (string)$_POST['token'])) {
    errorIframe('Sesión inválida. Recarga la página e inténtalo de nuevo.', 403);
}

try {
    $config = Config::cargar();

    // Tasa por IP (ventana deslizante)
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($ip !== '' && !Limites::tasaPorIp('stream', $ip, (int)$config->get('streamMaxPorMinuto', 6), 60)) {
        errorIframe('Demasiadas descargas en poco tiempo. Espera un momento.', 429);
    }

    // Ranura de transmisión concurrente
    $fh = Limites::slot('stream', (int)$config->get('streamConcurrentes', 3));
    if ($fh === null) {
        errorIframe('Servicio ocupado: hay varias descargas en curso. Espera unos segundos.', 503);
    }

    // Datos de la validación (10 min de vigencia)
    $datos = SesionDescargas::tomar((string)($_POST['id'] ?? ''));
    if ($datos === null) {
        Limites::liberar($fh);
        errorIframe('La solicitud expiró. Vuelve a intentar la descarga.', 410);
    }

    // Ya no se necesita la sesión: cerrarla libera el lock y permite
    // descargas en simultáneo desde la misma cuenta de navegador.
    if (isset($datos['url'], $datos['pl'])) {
        $plataforma = Plataformas::obtener((string)$datos['pl']);
        $url = Url::normalizar((string)$datos['url'], $plataforma);
        $meta = [
            'titulo' => (string)($datos['titulo'] ?? 'video'),
            'modo' => (string)($datos['modo'] ?? 'fusion'),
            'tam' => isset($datos['tam']) && is_numeric($datos['tam']) ? (int)$datos['tam'] : null,
        ];
    } else {
        throw new ExcepcionValidacion('Datos de la solicitud incompletos.', 'datos');
    }

    // Ya con todo validado, el flujo sigue sin necesitar $_SESSION.
    session_write_close();

    $transmisor = new Transmisor($config, $url, $meta, $plataforma, $fh);
    $primeros = $transmisor->iniciar();
    if (!$primeros['ok']) {
        errorIframe($primeros['mensaje'], $primeros['codigoHttp'] ?? 400);
    }
    $transmisor->enviarCabeceras();
    $transmisor->drenar();

} catch (ExcepcionValidacion $e) {
    errorIframe($e->getMessage());
} catch (Throwable $e) {
    error_log('[stream] ' . $e->getMessage());
    errorIframe('Error interno inesperado. Inténtalo de nuevo.', 500);
}
