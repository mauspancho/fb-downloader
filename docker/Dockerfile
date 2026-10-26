# Imagen: mausp/fb-downloader
# Fase: 2.0.0 — streaming sin persistencia de videos.
# Contexto de build: raíz del repositorio (docker-compose.yml lo define).

FROM php:8.3-apache

ARG YTDLP_VERSION=2026.10.21
ENV DEBIAN_FRONTEND=noninteractive

# Dependencias: FFmpeg para remux, yt-dlp en venv (sin --break-system-packages),
# curl para el healthcheck, procps/setsid para la gestión de procesos.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      ffmpeg python3 python3-venv curl ca-certificates procps util-linux \
 && python3 -m venv /opt/ytdlp \
 && /opt/ytdlp/bin/pip install --no-cache-dir "yt-dlp==${YTDLP_VERSION}" \
 && ln -s /opt/ytdlp/bin/yt-dlp /usr/local/bin/yt-dlp \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# Hardening de PHP
COPY docker/php/99-hardening.ini /usr/local/etc/php/conf.d/99-hardening.ini

# Apache: DocumentRoot reducido a public/, cabeceras de seguridad, streaming
COPY docker/apache/security.conf   /etc/apache2/conf-enabled/security.conf
COPY docker/apache/streaming.conf  /etc/apache2/conf-enabled/streaming.conf
COPY docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod headers

# Aplicación
COPY public/  /var/www/public/
COPY src/     /var/www/src/
COPY config/  /var/www/config/

# Permisos: el servidor web solo lee el código; esta web root no es escribible.
RUN chown -R root:root /var/www/public /var/www/src /var/www/config \
 && find /var/www/public /var/www/src /var/www/config -type d -exec chmod 755 {} + \
 && find /var/www/public /var/www/src /var/www/config -type f -exec chmod 644 {} +

# Entry point con gestión de señales y limpieza de slots/temporales
COPY docker/entrypoint.sh /usr/local/bin/fbdl-entrypoint
RUN chmod 755 /usr/local/bin/fbdl-entrypoint

EXPOSE 80
STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD curl -fsS http://127.0.0.1/ -o /dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/fbdl-entrypoint"]
