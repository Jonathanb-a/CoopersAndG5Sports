<?php

namespace App\Services\Import;

use App\Enums\StockMovementType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Services\StockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Escribe en la base de datos lo leído por InventoryReader. Es idempotente: los productos se
 * buscan por (tienda, slug) y las tallas por (producto, talla); lo que ya existe no se toca,
 * así que correrlo dos veces no duplica ni vuelve a sumar stock.
 */
class InventoryImporter
{
    public const NOTE = 'Importación de Excel';

    public function __construct(private StockService $stock) {}

    /**
     * @param  list<ImportedSheet>  $sheets
     * @return array<string, array{products_new: int, products_existing: int, variants_new: int, pieces_new: int, images: int}> por hoja
     */
    public function run(Store $store, array $sheets, bool $dryRun = false): array
    {
        $writtenFiles = [];

        try {
            return $dryRun
                ? $this->process($store, $sheets, true, $writtenFiles)
                : DB::transaction(fn () => $this->process($store, $sheets, false, $writtenFiles));
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($writtenFiles);

            throw $exception;
        }
    }

    /** @param  list<string>  $writtenFiles */
    private function process(Store $store, array $sheets, bool $dryRun, array &$writtenFiles): array
    {
        $results = [];

        foreach ($sheets as $sheet) {
            $result = ['products_new' => 0, 'products_existing' => 0, 'variants_new' => 0, 'pieces_new' => 0, 'images' => 0];

            $category = Category::query()->where('store_id', $store->id)->where('slug', $sheet->categorySlug)->first();
            if ($category === null && ! $dryRun) {
                $category = Category::create([
                    'store_id' => $store->id,
                    'name' => $sheet->title,
                    'slug' => $sheet->categorySlug,
                    'size_type' => $sheet->sizeType,
                ]);
            }

            foreach ($sheet->products as $imported) {
                $product = Product::withTrashed()
                    ->where('store_id', $store->id)
                    ->where('slug', $imported->slug)
                    ->first();

                if ($product === null) {
                    $result['products_new']++;

                    if (! $dryRun) {
                        $product = Product::create([
                            'store_id' => $store->id,
                            'category_id' => $category->id,
                            'brand_id' => $imported->brand
                                ? Brand::firstOrCreate(['slug' => Str::slug($imported->brand)], ['name' => $imported->brand])->id
                                : null,
                            'name' => $imported->name,
                            'slug' => $imported->slug,
                            'price' => $imported->price(),
                            'cost' => $imported->cost(),
                        ]);
                    }

                    $existingSizes = [];
                } else {
                    $result['products_existing']++;
                    $existingSizes = $product->variants()->withTrashed()->pluck('size')->all();
                }

                foreach ($imported->sizes as $size => $pieces) {
                    if (in_array((string) $size, $existingSizes, true)) {
                        continue;
                    }

                    $result['variants_new']++;
                    $result['pieces_new'] += $pieces;

                    if ($dryRun || $product->trashed()) {
                        continue;
                    }

                    $variant = $product->variants()->create(['size' => (string) $size, 'stock' => 0]);

                    if ($pieces > 0) {
                        $this->stock->change($variant, StockMovementType::Entry, $pieces, null, self::NOTE);
                    }
                }

                if (! $dryRun && ! $product->trashed() && ! $product->images()->exists() && ($bytes = $imported->imageBytes())) {
                    $path = "products/{$imported->slug}-".substr(md5($bytes), 0, 8).'.'.$imported->imageExtension();

                    Storage::disk('public')->put($path, $bytes);
                    $writtenFiles[] = $path;
                    $product->images()->create(['path' => $path, 'position' => 0]);
                    $result['images']++;
                }
            }

            $results[$sheet->title] = $result;
        }

        return $results;
    }
}
