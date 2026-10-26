# fb-downloader 2.0

Descargador web de videos públicos de **Facebook** e **Instagram**.
Transfiere los videos desde la plataforma de origen directamente al navegador
del usuario en modo *streaming*. **El servidor no almacena videos permanentemente.**

## Estructura

```
docker/          Dockerfile, Apache (vhost, cabeceras, streaming), PHP ini, entrypoint
public/          Única raíz servida por Apache (DocumentRoot /var/www/public)
  ├─ index.php           Interfaz
  ├─ politica.html       Política de uso
  └─ api/
      ├─ validar.php     POST JSON: CSRF + whitelist + metadatos con yt-dlp -J
      └─ stream.php      POST: transmisión progresiva (browser guarda el video)
src/             Lógica (App\): Config, Url, Plataformas, YtDlp, Transmisor, Limites
assets/          CSS/JS/SVG propios (sin CDN externos; CSP estricta)
config/          app.dist.php (defaults). Un app.php local sobreescribe.
```

## Arquitectura de descarga

1. `validar.php` revisa el enlace (solo hosts de Facebook/Instagram, https) pide
   los metadatos con `yt-dlp -J --skip-download` y devuelve `id` + título + tamaño.
2. `stream.php` toma esa referencia de sesión, lanza `yt-dlp -o -` por pipe:
   - modo **directo**: formato progresivo MP4 (H.264/AAC) -> bytes al navegador tal
     cual, con `Content-Length` real (barra de progreso nativa).
   - modo **fusion**: pistas separadas -> yt-dlp une a Matroska en el pipe y FFmpeg
     re-aporta a MP4 fragmentado (`+frag_keyframe+empty_moov`), un flujo válido
     para el navegador **sin escribir en el disco del servidor**.
3. El archivo se sirve por `Content-Disposition: attachment` con nombre derivado
   del título del video; un fallo durante la validación se contesta como HTML con
   mensaje legible en la interfaz (nunca como video).

Limitaciones documentadas:
- En modo fusion no se conoce el tamaño exacto de antemano (sin `Content-Length`).
- Si el video solo existe en códecs AV1/VP9 se sirven en calidad disponible; la
  compatibilidad de reproductores antiguos puede variar.
- Un video que exige sesión es rechazado con un mensaje claro.
- Los únicos archivos de la aplicación son referencias de sesión (10 min) y
  mecanismos de lock en `/tmp` (se limpian al arrancar el contenedor).

## Docker

```bash
docker compose up -d --build     # puerto 8089
docker compose logs -f           # ver actividad
docker compose down              # parar
```

Opciones (montadas solo lectura vía volumen ./config):

- `app.php` (claves como en `app.dist.php`)
- `cookies.txt`: cookies en formato Netscape para plataformas que exigen sesión.
  Si está activado indicar la ruta `/var/www/config/cookies.txt` con:
  ```php
  // config/app.php
  <?php return ['cookieFile' => '/var/www/config/cookies.txt'];
  ```

## Seguridad

- Validación estricta de enlaces (whitelist de hosts, https, sin caracteres de control).
- CSRF por sesión; token de una sola sesión por petición.
- `proc_open` con argumentos separados (nunca string shell) — no hay ejecución de comandos
  por defecto: el ejecutor siempre construye con `escapeshellarg` la line de pipe.
- Límites: 3 descargas simultáneas y tasa por IP; watchdog de stall/timeout que termina procesos.
- El web root solo contiene `public/` (código interno fuera del Docroot — no hay descargas de código).
- Apache sin configuración expositiva (ServerTokens Prod, sin TLS en este despliegue: usa un proxy TLS
  si expones el contenedor a internet directamente).
