<?php

namespace App\Models\Concerns;

use App\Http\Middleware\ResolveStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToStore
{
    protected static function bootBelongsToStore(): void
    {
        static::addGlobalScope('store', function (Builder $builder) {
            if (app()->bound(ResolveStore::CONTAINER_KEY)) {
                $builder->where(
                    $builder->getModel()->qualifyColumn('store_id'),
                    app(ResolveStore::CONTAINER_KEY)->id,
                );
            }
        });

        static::creating(function (Model $model) {
            if ($model->store_id === null && app()->bound(ResolveStore::CONTAINER_KEY)) {
                $model->store_id = app(ResolveStore::CONTAINER_KEY)->id;
            }
        });
    }
}
