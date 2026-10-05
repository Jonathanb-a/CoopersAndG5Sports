<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\Storage;

/**
 * Datos de una tarjeta de producto de la tienda (inicio y catálogo).
 * El producto debe traer cargados `images`, `brand` y la suma `total_stock` de sus tallas.
 */
class ProductCard
{
    /** @return array{id: int, name: string, brand: ?string, price: string, image_url: ?string, tag: ?string} */
    public static function from(Product $product): array
    {
        $path = $product->images->first()?->path;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'brand' => $product->brand?->name,
            'price' => $product->price,
            'image_url' => $path ? Storage::disk('public')->url($path) : null,
            'tag' => self::tag($product),
        ];
    }

    /** "Agotado", "Sobre pedido" y "Última pieza" (ver CLAUDE.md). */
    public static function tag(Product $product): ?string
    {
        $stock = (int) $product->total_stock;

        return match (true) {
            $stock === 0 && $product->allow_backorder => 'backorder',
            $stock === 0 => 'sold-out',
            $stock === 1 => 'last-piece',
            default => null,
        };
    }
}
