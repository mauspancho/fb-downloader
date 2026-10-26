<?php
require_once __DIR__ . '/../src/bootstrap.php';
csrf_token(); // inicia sesión y garantiza token en la página
?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Descargador FB · IG — Videos de Facebook e Instagram</title>
  <meta name="description" content="Descarga los videos públicos de Facebook e Instagram directamente a tu equipo, sin almacenamiento en el servidor.">
  <link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="/assets/css/main.css?v=202">
</head>
<body>
  <header class="cabecera">
    <a class="marca" href="/">
      <svg class="marca__icono" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="currentColor" opacity=".12"/><path d="M9.5 7.5v9l7-4.5z" fill="currentColor"/></svg>
      <span class="marca__texto">Descargador FB&nbsp;·&nbsp;IG</span>
    </a>
  </header>

  <main class="contenedor">
    <h1 class="titulo">Descarga videos de <span>Facebook</span> e <span>Instagram</span></h1>
    <p class="subtitulo">Pega el enlace público del video: el archivo va directo de la plataforma a tu equipo. Nada queda guardado en este servidor.</p>

    <div class="tarjetas">
      <!-- Facebook -->
      <section class="tarjeta tarjeta--fb" data-plataforma="fb" aria-labelledby="t-fb">
        <h2 class="tarjeta__titulo" id="t-fb">
          <svg class="tarjeta__icono" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M13.5 21v-7h2.4l.4-3h-2.8V9.1c0-.9.3-1.5 1.5-1.5H16V4.9c-.3 0-1.1-.06-2.2-.06-2.2 0-3.7 1.3-3.7 3.8V11H7.7v3h2.4v7z"/></svg>
          Facebook
        </h2>
        <form class="forml" method="post" action="/api/stream.php" target="fbdl_descarga" novalidate>
          <input type="hidden" name="p" value="fb">
          <input type="hidden" name="token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="id" value="">
          <label class="forml__etiqueta" for="fb_url">Enlace del video de Facebook</label>
          <div class="forml__fila">
            <input type="url" id="fb_url" name="u" class="forml__entrada" inputmode="url" autocomplete="off" spellcheck="false"
                   placeholder="https://www.facebook.com/watch/?v=…" required>
            <button type="submit" class="btn btn--fb">Descargar</button>
          </div>
        </form>
        <div class="estado estado--oculto" role="status" aria-live="polite"></div>
      </section>

      <!-- Instagram -->
      <section class="tarjeta tarjeta--ig" data-plataforma="ig" aria-labelledby="t-ig">
        <h2 class="tarjeta__titulo" id="t-ig">
          <svg class="tarjeta__icono" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2.2c3.2 0 3.6 0 4.9.07 1.2.05 1.8.25 2.2.41.6.23 1 .5 1.4.96.45.44.73.83.95 1.4.17.44.36 1.06.41 2.24.07 1.3.07 1.7.07 4.92s0 3.62-.07 4.92c-.05 1.18-.24 1.8-.41 2.24-.22.57-.5.96-.95 1.4a3.8 3.8 0 0 1-1.4.96c-.4.16-1 .36-2.2.41-1.3.07-1.7.07-4.9.07s-3.6 0-4.9-.07c-1.2-.05-1.8-.25-2.2-.41a3.8 3.8 0 0 1-1.4-.96 3.8 3.8 0 0 1-.95-1.4c-.17-.44-.36-1.06-.41-2.24C2.2 15.62 2.2 15.2 2.2 12s0-3.62.07-4.92c.05-1.18.24-1.8.41-2.24.22-.57.5-.96.95-1.4A3.8 3.8 0 0 1 5.03 2.5c.4-.16 1-.36 2.2-.41 1.3-.07 1.7-.07 4.9-.07zm0 2c-2.9 0-3.5.02-4.7.08-1 .03-1.5.2-1.9.34-.44.2-.7.34-1 .6-.35.28-.55.5-.7.94-.13.4-.3.96-.33 1.92-.07 1.2-.08 1.7-.08 4.92s.01 3.72.08 4.92c.03.96.2 1.52.33 1.92.15.44.35.66.7.94.3.26.56.4 1 .6.4.14.9.31 1.9.34 1.2.06 1.8.08 4.7.08s3.5-.02 4.7-.08c1-.03 1.46-.2 1.86-.34.44-.2.7-.34 1-.6.35-.28.55-.5.7-.94.13-.4.3-.96.33-1.92.07-1.2.08-1.7.08-4.92s-.01-3.72-.08-4.92c-.03-.96-.2-1.52-.33-1.92a2.6 2.6 0 0 0-.7-.94c-.3-.26-.56-.4-1-.6-.4-.14-.86-.31-1.86-.34-1.2-.06-1.8-.08-4.7-.08zm0 3.4A5.4 5.4 0 1 1 6.6 12 5.4 5.4 0 0 1 12 7.6zm0 8.9A3.5 3.5 0 1 0 15.5 12 3.5 3.5 0 0 0 12 16.5zm5.6-9.2a1.26 1.26 0 1 1-2.52 0 1.26 1.26 0 0 1 2.52 0z"/></svg>
          Instagram
        </h2>
        <form class="forml" method="post" action="/api/stream.php" target="fbdl_descarga" novalidate>
          <input type="hidden" name="p" value="ig">
          <input type="hidden" name="token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="id" value="">
          <label class="forml__etiqueta" for="ig_url">Enlace del video o reel de Instagram</label>
          <div class="forml__fila">
            <input type="url" id="ig_url" name="u" class="forml__entrada" inputmode="url" autocomplete="off" spellcheck="false"
                   placeholder="https://www.instagram.com/reel/…" required>
            <button type="submit" class="btn btn--ig">Descargar</button>
          </div>
        </form>
        <div class="estado estado--oculto" role="status" aria-live="polite"></div>
      </section>
    </div>

    <p class="nota">Si el enlace es de una publicación privada o requiere iniciar sesión, la descarga no será posible: solo se admiten videos públicos y disponibles.</p>
  </main>

  <footer class="pie">
    <p>Versión 2.0 — transmisión directa al navegador. <a href="/politica.html" class="pie__enlace">Política de uso</a></p>
  </footer>

  <iframe name="fbdl_descarga" class="frame-oculto" title="Descarga en curso" aria-hidden="true" tabindex="-1"></iframe>

  <script src="/assets/js/main.js?v=202" defer></script>
</body>
</html>
