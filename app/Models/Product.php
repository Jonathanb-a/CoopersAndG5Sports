<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToStore, SoftDeletes;

    protected $fillable = [
        'store_id',
        'category_id',
        'brand_id',
        'name',
        'slug',
        'color',
        'description',
        'price',
        'cost',
        'allow_backorder',
        'backorder_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'allow_backorder' => 'boolean',
            'backorder_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** Productos sin ninguna pieza (suma de stock de sus tallas = 0). */
    public function scopeSoldOut(Builder $query): Builder
    {
        return $this->whereTotalStock($query, 0);
    }

    /** Productos con exactamente una pieza en total. */
    public function scopeLastPiece(Builder $query): Builder
    {
        return $this->whereTotalStock($query, 1);
    }

    /** Productos en rojo o amarillo del semáforo: 2 piezas o menos en total (ver StockLevel::forProduct). */
    public function scopeNeedsRestock(Builder $query): Builder
    {
        return $this->whereTotalStock($query, 2, '<=');
    }

    private function whereTotalStock(Builder $query, int $total, string $operator = '='): Builder
    {
        return $query->whereRaw(
            "(select coalesce(sum(stock), 0) from product_variants where product_variants.product_id = {$this->getTable()}.id and product_variants.deleted_at is null) {$operator} ?",
            [$total],
        );
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }
}
