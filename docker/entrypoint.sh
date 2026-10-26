#!/bin/sh
# Entrada del contenedor: limpieza de slots/temporales, señales de Apache,
# y terminación de procesos de descarga huérfanos al apagar.
set -eu

APACHE_PID=""
LIMPIEZA() {
    # Ordenar detención de descargas activas para que ningún proceso quede colgado
    pkill -TERM -f yt-dlp 2>/dev/null || true
    pkill -TERM -f 'ffmpeg' 2>/dev/null || true
    if [ -n "${APACHE_PID:-}" ]; then
        kill -TERM "$APACHE_PID" 2>/dev/null || true
        wait "$APACHE_PID" 2>/dev/null || true
    fi
}
trap LIMPIEZA TERM INT

# Janitor de arranque (defensa en profundidad: /tmp es interno del contenedor)
rm -rf /tmp/fbdl-slots /tmp/fbdl-metas /tmp/fbdl-rate 2>/dev/null || true

apache2-foreground &
APACHE_PID=$!
wait "$APACHE_PID"
