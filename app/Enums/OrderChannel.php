<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderChannel: string implements HasLabel
{
    case Web = 'web';
    case Counter = 'mostrador';

    public function getLabel(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Counter => 'Mostrador',
        };
    }
}
