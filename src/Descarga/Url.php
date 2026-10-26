<?php
declare(strict_types=1);

namespace App\Descarga;

/** Normaliza y valida URLs contra la whitelist de la plataforma. */
final class Url
{
    /** Longitud máxima aceptada (los enlace largos de IG/FB tienen decenas de chars). */
    private const MAX_LARGO = 2048;

    public static function normalizar(string $bruta, Plataforma $plataforma): string
    {
        $bruta = trim($bruta);
        if ($bruta === '') {
            throw new ExcepcionValidacion(
                'Introduce un enlace.',
                ExcepcionValidacion::CODIGO['url_invalida']
            );
        }
        if (mb_strlen($bruta) > self::MAX_LARGO) {
            throw new ExcepcionValidacion(
                'El enlace es demasiado largo.',
                ExcepcionValidacion::CODIGO['url_invalida']
            );
        }

        // Elimina cualquier carácter de control o espacio (también \r, \n, tab)
        $bruta = preg_replace('/[\x00-\x20\x7F]+/u', '', $bruta) ?? '';

        $partes = parse_url($bruta);
        if (!is_array($partes) || !isset($partes['host'])) {
            throw new ExcepcionValidacion(
                'El enlace no tiene un formato de URL válido.',
                ExcepcionValidacion::CODIGO['url_invalida']
            );
        }

        // Solo https: si llega sin esquema lo asumimos; http se fuerza a https.
        $esquema = strtolower($partes['scheme'] ?? '');
        if ($esquema === '' ) {
            $bruta = 'https://' . $bruta;
            $partes = parse_url($bruta);
        } elseif ($esquema !== 'https') {
            if ($esquema === 'http') {
                $bruta = 'https://' . substr($bruta, 7);
                $partes = parse_url($bruta);
            } else {
                throw new ExcepcionValidacion(
                    'Solo se aceptan enlaces https://' . $plataforma->nombre() . '.',
                    ExcepcionValidacion::CODIGO['url_invalida']
                );
            }
        }
        if (!is_array($partes) || !isset($partes['host'])) {
            throw new ExcepcionValidacion(
                'El enlace no tiene un formato de URL válido.',
                ExcepcionValidacion::CODIGO['url_invalida']
            );
        }

        if (isset($partes['port'])) {
            throw new ExcepcionValidacion(
                'El enlace no puede tener puerto personalizado.',
                ExcepcionValidacion::CODIGO['url_invalida']
            );
        }

        $host = strtolower(idn_a_ascii($partes['host']));
        if ($host === '' || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new ExcepcionValidacion(
                'El dominio del enlace no es válido.',
                ExcepcionValidacion::CODIGO['url_invalida']
            );
        }
        if (!$plataforma->acepta($host)) {
            throw new ExcepcionValidacion(
                'El enlace no pertenece a ' . $plataforma->nombre() . '. Comprueba que copiaste el enlace correcto.',
                ExcepcionValidacion::CODIGO['host_no_permitido']
            );
        }

        // Reconstruye: https + host (+ path + query del original, sin fragmento)
        $reconstruida = 'https://' . $host . ($partes['path'] ?? '');
        if (isset($partes['query'])) {
            $reconstruida .= '?' . $partes['query'];
        }

        return $reconstruida;
    }
}

/** Convierte dominios IDN (con acentos) a punycode cuando la extensión intl existe. */
function idn_a_ascii(string $host): string
{
    if (preg_match('/[^\x00-\x7F]/', $host) === 1 && function_exists('idn_to_ascii')) {
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        return is_string($ascii) ? $ascii : '';
    }
    return $host;
}
