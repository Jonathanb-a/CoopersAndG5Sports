<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** El apartado de un pedido después de creado: darle más tiempo para pagar y reactivarlo si venció. */
class OrderReservationService
{
    public const EXTENSION_HOURS = [24, 48];

    public function __construct(
        private readonly OrderStatusService $statuses,
        private readonly StockService $stock,
    ) {}

    /**
     * Da más tiempo para pagar un pedido apartado: suma las horas a su fecha límite (o a este momento,
     * si la fecha ya pasó, para que el tiempo extra sea real) y lo anota en el historial.
     *
     * @throws InvalidOrderTransitionException
     */
    public function extend(Order $order, int $hours, User|Customer|null $actor = null): Order
    {
        if (! in_array($hours, self::EXTENSION_HOURS, true)) {
            throw new InvalidOrderTransitionException('Solo se pueden dar 24 o 48 horas más.');
        }

        DB::transaction(function () use ($order, $hours, $actor) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->getKey());

            if ($locked->status !== OrderStatus::Reserved) {
                throw new InvalidOrderTransitionException(
                    "El pedido {$locked->number} está «{$locked->statusLabel()}»: solo a un pedido apartado se le da más tiempo."
                );
            }

            $base = $locked->expires_at && $locked->expires_at->isFuture() ? $locked->expires_at->copy() : now();
            $locked->expires_at = $base->addHours($hours);
            $locked->save();

            $this->statuses->annotate(
                $locked,
                $actor,
                "Se dieron {$hours} horas más para pagar. Nueva fecha límite: {$locked->expires_at->format('d/m/Y H:i')}.",
            );
        });

        return $order->refresh();
    }

    /**
     * Reactiva un pedido que canceló el sistema sin haberse pagado: vuelve a apartar sus piezas y le
     * pone una fecha límite nueva. Revisa el stock con las tallas bloqueadas; si de alguna ya no hay
     * suficientes piezas no reactiva nada y dice cuál.
     *
     * @throws InvalidOrderTransitionException
     */
    public function reactivate(Order $order, User|Customer|null $actor = null): Order
    {
        DB::transaction(function () use ($order, $actor) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->getKey());

            if (! OrderStatusService::canReactivate($locked)) {
                throw new InvalidOrderTransitionException(
                    "El pedido {$locked->number} no se puede reactivar: solo los pedidos que canceló el sistema y que nunca se pagaron."
                );
            }

            // Solo las líneas normales apartan piezas; las de sobre pedido no tocan el stock.
            $items = $locked->items()->where('is_backorder', false)->orderBy('id')->get();
            $needed = $items->whereNotNull('product_variant_id')->groupBy('product_variant_id')->map->sum('quantity');

            $variants = ProductVariant::query()
                ->whereIn('id', $needed->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $missing = $items
                ->filter(fn ($item) => $item->product_variant_id === null
                    || ! $variants->has($item->product_variant_id)
                    || $variants[$item->product_variant_id]->stock < $needed[$item->product_variant_id])
                ->map(fn ($item) => "{$item->product_name} (talla {$item->size})")
                ->unique()
                ->values();

            if ($missing->isNotEmpty()) {
                throw new InvalidOrderTransitionException(
                    "No se reactivó el pedido {$locked->number}: ya no hay piezas suficientes de ".$missing->join(', ', ' y ').'.'
                );
            }

            $this->statuses->reactivate($locked, $actor, 'Pedido reactivado: se apartaron de nuevo las piezas.');

            foreach ($items as $item) {
                $this->stock->change(
                    $variants[$item->product_variant_id],
                    StockMovementType::WebSale,
                    -$item->quantity,
                    $actor instanceof User ? $actor : null,
                    "Reactivación del pedido {$locked->number}",
                    $locked,
                );
            }

            // Al cancelar, el cupón recuperó su uso: al reactivar lo vuelve a ocupar.
            if ($locked->coupon_id) {
                Coupon::withoutGlobalScopes()->whereKey($locked->coupon_id)->increment('used_count');
            }

            $locked->refresh()->update(['expires_at' => now()->addHours((int) config('shop.reserve_hours', 48))]);
        });

        return $order->refresh();
    }
}
