<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class StoreUsers
{
    /**
     * Usuarios a los que avisar de lo que pasa en una tienda: los ligados a ella en
     * store_user y los owner (que ven todas las tiendas).
     *
     * @return Collection<int, User>
     */
    public static function toNotify(Store $store): Collection
    {
        return User::query()
            ->where('role', UserRole::Owner->value)
            ->orWhereHas('stores', fn ($query) => $query->whereKey($store->getKey()))
            ->get();
    }
}
