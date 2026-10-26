<?php
declare(strict_types=1);

namespace App\Descarga;

final class Instagram extends Plataforma
{
    public function hosts(): array
    {
        return [
            'instagram.com',
            'www.instagram.com',
            'instagr.am',
            'www.instagr.am',
        ];
    }

    public function codigo(): string
    {
        return 'ig';
    }

    public function nombre(): string
    {
        return 'Instagram';
    }
}
