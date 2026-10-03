<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientStockException extends RuntimeException
{
    public static function for(int $available, int $change): self
    {
        return new self("Stock insuficiente: hay {$available} pieza(s) y el movimiento es de {$change}.");
    }
}
