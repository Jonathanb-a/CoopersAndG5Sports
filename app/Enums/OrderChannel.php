<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderChannel: string implements HasColor, HasLabel
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

    public function getColor(): string
    {
        return match ($this) {
            self::Web => 'info',
            self::Counter => 'gray',
        };
    }
}
