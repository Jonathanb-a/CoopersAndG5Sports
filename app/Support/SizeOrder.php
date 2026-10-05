<?php

namespace App\Support;

/** Orden de tallas: primero las numéricas de menor a mayor, luego las de letras (XS, S, M...) y al final cualquier otra. */
class SizeOrder
{
    private const LETTER_SIZES = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];

    public static function compare(string $a, string $b): int
    {
        return self::rank($a) <=> self::rank($b);
    }

    /** @return array{0: int, 1: float|int|string} */
    private static function rank(string $size): array
    {
        if (is_numeric($size)) {
            return [0, (float) $size];
        }

        $letter = array_search(strtoupper($size), self::LETTER_SIZES, true);

        return $letter === false ? [2, mb_strtolower($size)] : [1, $letter];
    }
}
