<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveStore;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Support\ProductCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/** Inicio de la tienda: banners, accesos a categorías, lo más nuevo y «Quiénes somos». */
class StoreHomeController extends Controller
{
    private const NEWEST_LIMIT = 8;

    public function __invoke(): Response
    {
        /** @var Store $store */
        $store = app(ResolveStore::CONTAINER_KEY);

        return Inertia::render('home', [
            'banners' => $this->banners($store),
            'categoryCards' => $this->categoryCards($store),
            'newest' => $this->newest($store),
            'about' => trim((string) $store->about_text) ?: null,
        ]);
    }

    /** Solo los banners activos, en el orden del panel. */
    private function banners(Store $store): array
    {
        return Banner::query()
            ->where('store_id', $store->id)
            ->visible()
            ->get()
            ->map(fn (Banner $banner) => [
                'id' => $banner->id,
                'image_url' => Storage::disk('public')->url($banner->image_path),
                'title' => $banner->title,
                'link_url' => $banner->link_url,
            ])
            ->all();
    }

    /** Categorías con al menos un producto activo, con la foto del producto más reciente que tenga. */
    private function categoryCards(Store $store): array
    {
        $categories = Category::query()
            ->where('store_id', $store->id)
            ->whereHas('products', fn ($query) => $query->where('is_active', true))
            ->withCount(['products as products_count' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('name')
            ->get();

        $photos = DB::table('product_images')
            ->join('products', 'products.id', '=', 'product_images.product_id')
            ->where('products.store_id', $store->id)
            ->where('products.is_active', true)
            ->whereNull('products.deleted_at')
            ->whereIn('products.category_id', $categories->pluck('id'))
            ->orderByDesc('products.id')
            ->orderBy('product_images.position')
            ->get(['products.category_id', 'product_images.path'])
            ->unique('category_id')
            ->pluck('path', 'category_id');

        return $categories
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'products_count' => $category->products_count,
                'image_url' => isset($photos[$category->id]) ? Storage::disk('public')->url($photos[$category->id]) : null,
            ])
            ->all();
    }

    /** Los productos activos más recientes, con su etiqueta de existencias. */
    private function newest(Store $store): array
    {
        return Product::query()
            ->where('products.store_id', $store->id)
            ->where('products.is_active', true)
            ->with(['images', 'brand'])
            ->withSum('variants as total_stock', 'stock')
            ->latest('products.created_at')
            ->latest('products.id')
            ->limit(self::NEWEST_LIMIT)
            ->get()
            ->map(fn (Product $product) => ProductCard::from($product))
            ->all();
    }
}
