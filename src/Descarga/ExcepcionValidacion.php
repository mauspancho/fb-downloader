<?php
declare(strict_types=1);

namespace App\Descarga;

/** Error controlado de validación que llega como mensaje comprensible al usuario. */
final class ExcepcionValidacion extends \RuntimeException
{
    /** @var array<string,string> códigos estándar */
    public const CODIGO = [
        'url_invalida'   => 'url_invalida',
        'host_no_permitido' => 'host_no_permitido',
        'plataforma'     => 'plataforma_desconocida',
        'sesion'         => 'sesion_invalida',
        'limite'         => 'limite_alcanzado',
        'plataforma_bloqueo' => 'plataforma_exige_sesion',
        'sin_video'      => 'video_no_disponible',
        'no_encontrado'  => 'video_no_encontrado',
        'extraccion'     => 'error_extraccion',
        'timeout'        => 'timeout_metadatos',
        'formato'        => 'formato_no_disponible',
    ];

    public string $codigo;

    public function __construct(string $mensaje, string $codigo = self::CODIGO['extraccion'])
    {
        parent::__construct($mensaje);
        $this->codigo = $codigo;
    }
}
