<?php
/**
 * Configuración por defecto de fb-downloader 2.0.
 * Puedes crear config/app.php (no en el repo) para sobreescribir valores;
 * el comercial docker-compose monta ./config como /var/www/config (solo lectura).
 */
return [
    // Concurrencia y tasas
    'streamConcurrentes' => 3,      // descargas simultáneas máximas
    'metaConcurrentes'   => 4,      // análisis/validación simultáneos
    'streamMaxPorMinuto' => 6,      // por IP
    'metaMaxPorMinuto'   => 8,      // por IP

    // Tiempos (segundos)
    'validateTimeout'    => 25,     // metadatos (yt-dlp -J)
    'streamTimeout'      => 600,    // transmisión completa

    // Cookies opcionales para Instagram/Facebook en modo invitado.
    // Ruta DENTRO del contenedor, p. ej. /var/www/config/cookies.txt (volúmen montado).
    'cookieFile'         => null,
];
