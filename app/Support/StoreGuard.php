<?php

namespace App\Support;

use App\Exceptions\CrossStoreAccessException;
use App\Http\Middleware\ResolveStore;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;

/**
 * Candado de tienda de los servicios que escriben. Los servicios bloquean el pedido por ID sin el
 * scope de tienda, así que cada uno comprueba aquí que quien lo hace puede tocarlo:
 *  - User (staff u owner): debe poder acceder a la tienda del pedido (el owner, a todas).
 *  - Customer: el pedido debe ser suyo y de la tienda de la petición actual.
 *  - null: el sistema (comandos que recorren todas las tiendas a propósito). Pasa sin revisar.
 */
class StoreGuard
{
    public const MESSAGE = 'No tienes acceso a este pedido.';

    /** @throws CrossStoreAccessException */
    public static function authorize(Order $order, User|Customer|null $actor): void
    {
        if ($actor === null) {
            return;
        }

        if ($actor instanceof User) {
            $store = Store::query()->find($order->store_id);

            if ($store === null || ! $actor->canAccessTenant($store)) {
                throw new CrossStoreAccessException(self::MESSAGE);
            }

            return;
        }

        $current = app()->bound(ResolveStore::CONTAINER_KEY) ? app(ResolveStore::CONTAINER_KEY) : null;

        if ($current === null
            || (int) $order->store_id !== (int) $current->getKey()
            || (int) $order->customer_id !== (int) $actor->getKey()) {
            throw new CrossStoreAccessException(self::MESSAGE);
        }
    }

    /** La pieza y el pedido deben ser de la misma tienda (variante → producto → store_id). */
    public static function assertSameStore(ProductVariant $variant, Order $order): void
    {
        $storeId = Product::withoutGlobalScopes()->whereKey($variant->product_id)->value('store_id');

        if ($storeId === null || (int) $storeId !== (int) $order->store_id) {
            throw new CrossStoreAccessException('La pieza y el pedido son de tiendas distintas.');
        }
    }
}
