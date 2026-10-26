<?php
declare(strict_types=1);

/**
 * Arranque de la aplicación: autocarga simple para classes con namespace App\.
 * Estructura: src/Descarga/YtDlp.php -> App\Descarga\YtDlp
 */

const APP_CONFIG_DIR = __DIR__ . '/../config';

spl_autoload_register(static function (string $clase): void {
    if (str_starts_with($clase, 'App\\')) {
        $ruta = __DIR__ . '/' . str_replace('\\', '/', substr($clase, 4)) . '.php';
        if (is_file($ruta)) {
            require $ruta;
        }
    }
});

/** Inicializa la sesión con parámetros conservadores (se usa en index y en la API). */
function sesión_iniciar(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('fbdl_s');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/** Token CSRF de la sesión (creado si no existe). */
function csrf_token(): string
{
    sesión_iniciar();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
