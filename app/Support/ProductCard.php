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
    /** @return array{id: int, slug: string, name: string, brand: ?string, price: string, image_url: ?string, second_image_url: ?string, tag: ?string} */
    public static function from(Product $product): array
    {
        $path = $product->images->first()?->path;
        $secondPath = $product->images->skip(1)->first()?->path;

        return [
            'id' => $product->id,
            'slug' => $product->slug,
            'name' => $product->name,
            'brand' => $product->brand?->name,
            'price' => $product->price,
            'image_url' => $path ? Storage::disk('public')->url($path) : null,
            // Segunda foto, para el cambio al pasar el cursor en temas con movimiento.
            'second_image_url' => $secondPath ? Storage::disk('public')->url($secondPath) : null,
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
