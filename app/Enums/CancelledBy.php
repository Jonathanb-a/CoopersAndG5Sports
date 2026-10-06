<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Quién canceló un pedido. */
enum CancelledBy: string implements HasLabel
{
    case Customer = 'customer';
    case Admin = 'admin';
    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::Customer => 'El cliente',
            self::Admin => 'La tienda',
            self::System => 'El sistema',
        };
    }
}
