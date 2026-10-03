<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Semáforo de inventario.
 * Por talla: 0 = rojo, 1 = amarillo, 2 o más = verde.
 * Por producto (suma de tallas): 0 = rojo, 1 a 2 = amarillo, 3 o más = verde.
 */
enum StockLevel: string implements HasColor, HasLabel
{
    case Out = 'out';
    case Low = 'low';
    case Ok = 'ok';

    public static function forVariant(int $stock): self
    {
        return match (true) {
            $stock <= 0 => self::Out,
            $stock === 1 => self::Low,
            default => self::Ok,
        };
    }

    public static function forProduct(int $totalStock): self
    {
        return match (true) {
            $totalStock <= 0 => self::Out,
            $totalStock <= 2 => self::Low,
            default => self::Ok,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Out => 'danger',
            self::Low => 'warning',
            self::Ok => 'success',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Out => 'Agotado',
            self::Low => 'Poco stock',
            self::Ok => 'Disponible',
        };
    }
}
