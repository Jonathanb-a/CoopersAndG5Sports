<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Entry = 'entrada';
    case WebSale = 'venta_web';
    case CounterSale = 'venta_mostrador';
    case Adjustment = 'ajuste';
    case Cancellation = 'cancelacion';
}
