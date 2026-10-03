<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Banner extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id',
        'image_path',
        'title',
        'link_url',
        'position',
        'is_active',
    ];

    protected static function booted(): void
    {
        // Al borrar un banner se borra también su imagen del disco.
        static::deleted(fn (Banner $banner) => Storage::disk('public')->delete($banner->image_path));
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Banners que se muestran en la tienda, en el orden elegido en el panel. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position')->orderBy('id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
