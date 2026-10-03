<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockMovementType: string implements HasColor, HasLabel
{
    case Entry = 'entrada';
    case WebSale = 'venta_web';
    case CounterSale = 'venta_mostrador';
    case Adjustment = 'ajuste';
    case Cancellation = 'cancelacion';

    public function getLabel(): string
    {
        return match ($this) {
            self::Entry => 'Entrada',
            self::WebSale => 'Venta web',
            self::CounterSale => 'Venta en mostrador',
            self::Adjustment => 'Ajuste',
            self::Cancellation => 'Cancelación',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Entry => 'success',
            self::WebSale, self::CounterSale => 'info',
            self::Adjustment => 'warning',
            self::Cancellation => 'gray',
        };
    }
}
