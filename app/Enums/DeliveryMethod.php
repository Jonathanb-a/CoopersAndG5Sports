<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum DeliveryMethod: string implements HasLabel
{
    case Pickup = 'recoger';
    case Local = 'local';
    case National = 'nacional';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pickup => 'Recoger en tienda',
            self::Local => 'Entrega local',
            self::National => 'Envío nacional',
        };
    }
}
