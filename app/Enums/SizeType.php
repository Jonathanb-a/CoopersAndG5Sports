<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SizeType: string implements HasLabel
{
    case Footwear = 'calzado';
    case Clothing = 'ropa';
    case Kids = 'kids';
    case OneSize = 'unica';

    public function getLabel(): string
    {
        return match ($this) {
            self::Footwear => 'Calzado',
            self::Clothing => 'Ropa',
            self::Kids => 'Niños',
            self::OneSize => 'Talla única',
        };
    }
}
