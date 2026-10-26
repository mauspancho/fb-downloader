<?php
declare(strict_types=1);

namespace App\Descarga;

final class Facebook extends Plataforma
{
    public function hosts(): array
    {
        return [
            'facebook.com',
            'www.facebook.com',
            'm.facebook.com',
            'mbasic.facebook.com',
            'fb.watch',
            'fb.com',
            'www.fb.com',
        ];
    }

    public function codigo(): string
    {
        return 'fb';
    }

    public function nombre(): string
    {
        return 'Facebook';
    }
}
