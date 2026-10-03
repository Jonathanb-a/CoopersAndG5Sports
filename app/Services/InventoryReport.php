<?php

namespace App\Services;

use App\Models\Store;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cifras de inventario de una tienda para el dashboard del panel.
 *
 * Piezas y valor cuentan todo lo que hay físicamente (productos y tallas sin borrar).
 * Las alertas (tallas agotadas y en última pieza) solo cuentan productos activos.
 */
class InventoryReport
{
    /**
     * @return array{active_products: int, pieces: int, sold_out_sizes: int, last_piece_sizes: int, retail_value: float, cost_value: float}
     */
    public function summary(Store $store): array
    {
        $totals = $this->variants($store)
            ->selectRaw('COALESCE(SUM(product_variants.stock), 0) as pieces')
            ->selectRaw('COALESCE(SUM(product_variants.stock * products.price), 0) as retail_value')
            ->selectRaw('COALESCE(SUM(product_variants.stock * COALESCE(products.cost, 0)), 0) as cost_value')
            ->first();

        $alerts = $this->variants($store)
            ->where('products.is_active', true)
            ->selectRaw('COALESCE(SUM(CASE WHEN product_variants.stock = 0 THEN 1 ELSE 0 END), 0) as sold_out_sizes')
            ->selectRaw('COALESCE(SUM(CASE WHEN product_variants.stock = 1 THEN 1 ELSE 0 END), 0) as last_piece_sizes')
            ->first();

        return [
            'active_products' => DB::table('products')
                ->where('store_id', $store->getKey())
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->count(),
            'pieces' => (int) $totals->pieces,
            'sold_out_sizes' => (int) $alerts->sold_out_sizes,
            'last_piece_sizes' => (int) $alerts->last_piece_sizes,
            'retail_value' => (float) $totals->retail_value,
            'cost_value' => (float) $totals->cost_value,
        ];
    }

    /**
     * Piezas por categoría (solo categorías con al menos un producto), de mayor a menor.
     *
     * @return array<string, int> nombre de la categoría => piezas
     */
    public function piecesByCategory(Store $store): array
    {
        return DB::table('categories')
            ->join('products', fn ($join) => $join
                ->on('products.category_id', '=', 'categories.id')
                ->whereNull('products.deleted_at'))
            ->leftJoin('product_variants', fn ($join) => $join
                ->on('product_variants.product_id', '=', 'products.id')
                ->whereNull('product_variants.deleted_at'))
            ->where('categories.store_id', $store->getKey())
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('categories.name as name, COALESCE(SUM(product_variants.stock), 0) as pieces')
            ->orderByDesc('pieces')
            ->orderBy('categories.name')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->name => (int) $row->pieces])
            ->all();
    }

    /** Tallas vigentes de productos vigentes de la tienda. */
    private function variants(Store $store): Builder
    {
        return DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.store_id', $store->getKey())
            ->whereNull('products.deleted_at')
            ->whereNull('product_variants.deleted_at');
    }
}
