<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Resultado de un intento de entrega a domicilio. */
enum DeliveryAttemptResult: string implements HasLabel
{
    case Delivered = 'delivered';
    case NotHome = 'not_home';
    case Rejected = 'rejected';
    case WrongAddress = 'wrong_address';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Delivered => 'Entregado',
            self::NotHome => 'No estaba',
            self::Rejected => 'Rechazó el pedido',
            self::WrongAddress => 'Dirección incorrecta',
            self::Other => 'Otro',
        };
    }

    /** Todo lo que no es «Entregado» es un intento fallido. */
    public function failed(): bool
    {
        return $this !== self::Delivered;
    }
}
