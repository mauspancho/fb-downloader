/* fb-downloader 2.0.1 — lógica de cliente.
 * Flujo: validar (fetch JSON) -> transmisión (form POST nativo al iframe oculto,
 * el navegador gestiona la descarga en streaming sin cargar el video en memoria).
 *
 * Estados del chip (aria-live):
 *  - Validando…        hasta respuesta del endpoint de metadatos
 *  - Descargando…      tras el POST del form
 *  - Error (chip rojo) si el servidor devuelve página de error (iframe load)
 *  - "Descarga iniciada" (fallback): los navegadores NO emiten ningún evento
 *    del progreso/final de una descarga nativa, así que se resuelve el estado
 *    con un temporizador propio; el error tardío sigue detectándose por el
 *    evento load del iframe.
 */
"use strict";

(function () {
  const REGEX_HOST = /^(?:https?:\/\/)?(?:[\w-]+\.)*(?:facebook\.com|fb\.watch|fb\.com|instagram\.com|instagr\.am)\/\S*$/i;
  const MAX_LARGO = 2048;
  const FALLBACK_ENVIO_MS = 1500;   // resolver "Descargando…" aunque el iframe no hable
  const VENTANA_ERROR_MS = 15 * 60 * 1000; // errores tardíos visibles

  const tarjetas = Array.from(document.querySelectorAll(".tarjeta[data-plataforma]"));
  if (!tarjetas.length) return;
  const $frame = document.querySelector("iframe[name='fbdl_descarga']");

  function mostrar($estado, chipHtml, clase) {
    $estado.classList.remove("estado--oculto");
    $estado.innerHTML = '<div class="chip ' + (clase || "") + '">' + chipHtml + "</div>";
  }

  function ocultar($estado) {
    $estado.classList.add("estado--oculto");
    $estado.innerHTML = "";
  }

  function mensajeError($estado, texto) {
    mostrar($estado, texto, "chip--err");
  }

  // Evento load del iframe: llega con una página de error del servidor, o con
  // un documento vacío cuando (algunos navegadores) cierran la navegación
  // por el attachment. En descargas correctas puede no dispararse nunca.
  $frame.addEventListener("load", function () {
    const tarjeta = tarjetas.find(function (t) {
      const $e = t.querySelector(".estado");
      const reciente = t._enviadaEn && (Date.now() - t._enviadaEn) < VENTANA_ERROR_MS;
      return $e && $e.dataset.pendiente === "1" || reciente;
    });
    if (!tarjeta) return;

    const $estado = tarjeta.querySelector(".estado");
    let texto = null;
    try {
      const doc = $frame.contentDocument;
      const errEl = doc && doc.querySelector("[data-error]");
      if (errEl) {
        texto = (errEl.textContent || "").replace(/^\s*Error:\s*/, "").replace(/fbdl-err$/, "").trim();
      }
    } catch (e) { /* sin acceso al doc: no es una página de error */ }

    if (texto) {
      mensajeError($estado, texto);
      tarjeta._enviadaEn = 0;
      const d = tarjeta._desbloquear;
      if (d) d();
    } else if ($estado.dataset.pendiente === "1") {
      // éxito confirmado por el propio iframe (antes del fallback)
      resolverExito(tarjeta);
    }
  });

  function resolverExito(tarjeta) {
    const $estado = tarjeta.querySelector(".estado");
    $estado.dataset.pendiente = "";
    tarjeta._enviadaEn = Date.now(); // ventana para capturar errores tardíos
    mostrar(
      $estado,
      '<span aria-hidden="true">✓ </span>Descarga iniciada: el navegador está guardando el video. Revisa la barra de descargas.',
      "chip--ok"
    );
    const d = tarjeta._desbloquear;
    if (d) d();
  }

  tarjetas.forEach(function (tarjeta) {
    const plataforma = tarjeta.dataset.plataforma;
    const forma = tarjeta.querySelector("form");
    const entrada = tarjeta.querySelector("input[name='u']");
    const boton = tarjeta.querySelector("button[type='submit']");
    const campoId = tarjeta.querySelector("input[name='id']");
    const $estado = tarjeta.querySelector(".estado");
    let ocupada = false;

    function desbloquear() {
      ocupada = false;
      $estado.dataset.pendiente = "";
      boton.disabled = false;
      boton.removeAttribute("aria-busy");
      boton.textContent = "Descargar";
    }
    tarjeta._desbloquear = desbloquear;

    function validarLocal(texto) {
      if (!texto) return "Introduce el enlace del video.";
      if (texto.length > MAX_LARGO) return "El enlace es demasiado largo.";
      if (!REGEX_HOST.test(texto)) {
        return "Ese enlace no parece de " + (plataforma === "fb" ? "Facebook" : "Instagram") + ".";
      }
      return null;
    }

    forma.addEventListener("submit", function (ev) {
      ev.preventDefault();
      if (ocupada) return;
      if (forma.dataset.fase === "2") { ocupada = false; return; }

      const texto = entrada.value.trim();
      const errorLocal = validarLocal(texto);
      if (errorLocal) {
        mensajeError($estado, errorLocal);
        entrada.focus();
        return;
      }

      ocupada = true;
      boton.disabled = true;
      boton.setAttribute("aria-busy", "true");

      // Fase 1: validar + metadatos
      mostrar($estado, '<span class="vueltas" aria-hidden="true"></span><span>Validando el enlace…</span>');

      const datos = new FormData(forma);
      const controlador = new AbortController();
      const vigilante = setTimeout(function () { controlador.abort(); }, 30000);

      fetch("/api/validar.php", { method: "POST", body: datos, signal: controlador.signal })
        .then(function (r) { return r.json().then(function (j) { return { http: r.status, json: j }; }); })
        .then(function (res) {
          clearTimeout(vigilante);
          if (!res.json.ok) {
            mensajeError($estado, res.json.mensaje || "No se pudo procesar el enlace.");
            desbloquear();
            return;
          }

          // Fase 2: transmisión (POST nativo del form hacia el iframe)
          $estado.dataset.pendiente = "1";
          mostrar(
            $estado,
            '<span class="vueltas" aria-hidden="true"></span><span>Descargando… si el navegador pregunta dónde guardar, elige la carpeta.</span>'
            + '<div class="barra" aria-hidden="true"><div class="barra__lleno barra__lleno--indeterminado"></div></div>'
          );
          campoId.value = res.json.id;
          forma.dataset.fase = "2";
          try {
            forma.submit();
            // Fallback: si el iframe no dispara load (comportamiento normal en
            // las descargas nativas), el estado se resuelve solo.
            window.setTimeout(function () {
              if ($estado.dataset.pendiente === "1") {
                resolverExito(tarjeta);
                tarjeta.querySelector("input[name='u']").value = "";
              }
            }, FALLBACK_ENVIO_MS);
          } finally {
            forma.dataset.fase = "";
          }
        })
        .catch(function (e) {
          clearTimeout(vigilante);
          mensajeError($estado, e.name === "AbortError"
            ? "La validación tardó demasiado. Comprueba tu conexión e inténtalo de nuevo."
            : "No hubo respuesta del servidor. Revisa tu conexión e inténtalo otra vez.");
          desbloquear();
        });
    });

    // Limpiar el mensaje al teclear un nuevo enlace
    entrada.addEventListener("input", function () {
      if ($estado && !$estado.classList.contains("estado--oculto")) {
        ocultar($estado);
      }
    });
  });
})();
