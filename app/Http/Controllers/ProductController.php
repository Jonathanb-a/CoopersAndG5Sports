<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveStore;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Support\ProductCard;
use App\Support\SizeOrder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/** Página de un producto de la tienda: /producto/{slug}. */
class ProductController extends Controller
{
    private const RELATED_LIMIT = 4;

    public function __invoke(string $slug): Response
    {
        /** @var Store $store */
        $store = app(ResolveStore::CONTAINER_KEY);

        // Solo productos de esta tienda, activos y sin borrar: cualquier otro responde 404.
        $product = Product::query()
            ->where('products.store_id', $store->id)
            ->where('products.is_active', true)
            ->where('products.slug', $slug)
            ->with(['images', 'brand', 'category', 'variants'])
            ->firstOrFail();

        $images = $product->images
            ->map(fn ($image) => Storage::disk('public')->url($image->path))
            ->values()
            ->all();

        $url = url('/producto/'.$product->slug);

        return Inertia::render('product', [
            'product' => [
                'id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'brand' => $product->brand?->name,
                'color' => $product->color,
                'price' => $product->price,
                'description' => trim((string) $product->description) ?: null,
                'allow_backorder' => $product->allow_backorder,
                'backorder_days' => $product->allow_backorder ? $product->backorder_days : null,
                'images' => $images,
                'sizes' => $this->sizes($product),
                'category' => $product->category?->only(['name', 'slug']),
            ],
            'related' => $this->related($product, $store),
            'whatsapp' => [
                'url' => ($number = $store->whatsappNumber()) ? "https://wa.me/{$number}" : null,
                'product_url' => $url,
            ],
        ])->withViewData(['meta' => $this->meta($product, $store, $url, $images[0] ?? null)]);
    }

    /**
     * Tallas con su estado. Las agotadas sin sobre pedido no se pueden elegir.
     * No se publica el stock exacto, solo el estado.
     *
     * @return list<array{size: string, status: string, selectable: bool}>
     */
    private function sizes(Product $product): array
    {
        return $product->variants
            ->sort(fn (ProductVariant $a, ProductVariant $b) => SizeOrder::compare($a->size, $b->size))
            ->map(function (ProductVariant $variant) use ($product) {
                $status = match (true) {
                    $variant->stock >= 2 => 'available',
                    $variant->stock === 1 => 'last-piece',
                    $product->allow_backorder => 'backorder',
                    default => 'sold-out',
                };

                return ['size' => $variant->size, 'status' => $status, 'selectable' => $status !== 'sold-out'];
            })
            ->values()
            ->all();
    }

    /** Hasta 4 productos de la misma categoría (activos, de esta tienda), los más nuevos primero. */
    private function related(Product $product, Store $store): array
    {
        return Product::query()
            ->where('products.store_id', $store->id)
            ->where('products.is_active', true)
            ->where('products.category_id', $product->category_id)
            ->whereKeyNot($product->id)
            ->with(['images', 'brand'])
            ->withSum('variants as total_stock', 'stock')
            ->latest('products.created_at')
            ->latest('products.id')
            ->limit(self::RELATED_LIMIT)
            ->get()
            ->map(fn (Product $related) => ProductCard::from($related))
            ->all();
    }

    /** Título, descripción e imagen para compartir en redes (Open Graph); salen en el HTML, no por JavaScript. */
    private function meta(Product $product, Store $store, string $url, ?string $image): array
    {
        $title = collect([$product->brand?->name, $product->name, $product->color])->filter()->implode(' ');

        $description = Str::limit(
            preg_replace('/\s+/u', ' ', trim((string) $product->description)) ?: "{$title} en {$store->name}.",
            200,
        );

        return [
            'title' => "{$title} · {$store->name}",
            'description' => $description,
            'url' => $url,
            'image' => $image ? url($image) : null,
            'site_name' => $store->name,
        ];
    }
}
