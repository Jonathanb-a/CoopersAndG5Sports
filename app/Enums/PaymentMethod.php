<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Con qué se pagó una venta de mostrador. */
enum PaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case Transfer = 'transfer';
    case Card = 'card';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Transfer => 'Transferencia',
            self::Card => 'Tarjeta',
        };
    }
}
