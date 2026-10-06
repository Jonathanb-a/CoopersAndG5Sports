<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cancela un pedido. Lo usan el panel, el cliente y la expiración automática de apartados.
 * Reglas de CLAUDE.md: solo se cancelan pedidos apartados o pagados; un pedido pagado solo lo
 * cancela el owner; cancelar regresa las piezas (salvo que se pida que no) y el cupón recupera su uso.
 */
class OrderCancellationService
{
    public const NOT_RESTOCKED_NOTE = 'No se regresaron piezas al inventario.';

    public function __construct(
        private readonly OrderStatusService $statuses,
        private readonly StockService $stock,
    ) {}

    /**
     * @param  User|Customer|null  $actor  quién cancela: personal del panel, el cliente, o null si es el sistema
     * @param  bool  $restock  true regresa al inventario las piezas de las líneas normales (las de sobre pedido nunca se descontaron)
     * @param  bool  $refunded  el pedido estaba pagado y ya se devolvió el dinero: fija refunded_at
     *
     * @throws InvalidOrderTransitionException si el pedido no está apartado ni pagado, o si no le toca a ese actor
     */
    public function cancel(Order $order, User|Customer|null $actor = null, ?string $reason = null, bool $restock = true, bool $refunded = false): Order
    {
        $reason = filled($reason) ? trim($reason) : null;

        DB::transaction(function () use ($order, $actor, $reason, $restock, $refunded) {
            // Con el pedido bloqueado: si dos personas cancelan a la vez (o hay doble clic), la segunda
            // lo encuentra ya cancelado y no regresa el stock otra vez.
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->getKey());
            $wasPaid = $locked->status === OrderStatus::Paid;

            if (! in_array($locked->status, [OrderStatus::Reserved, OrderStatus::Paid], true)) {
                throw new InvalidOrderTransitionException(
                    "El pedido {$locked->number} está «{$locked->statusLabel()}» y ya no se puede cancelar."
                );
            }

            if ($wasPaid && ! ($actor instanceof User && $actor->role === UserRole::Owner)) {
                throw new InvalidOrderTransitionException("El pedido {$locked->number} ya está pagado: solo el dueño puede cancelarlo.");
            }

            // Estado, cancelled_at y cancelled_by los pone OrderStatusService. En el historial queda
            // además si las piezas no regresaron; el motivo del pedido se guarda limpio.
            $note = $restock ? $reason : trim(($reason ? rtrim($reason, '.').'. ' : '').self::NOT_RESTOCKED_NOTE);

            $this->statuses->transition($locked, OrderStatus::Cancelled, $actor, $note);

            $locked->cancel_reason = $reason;

            if ($wasPaid && $refunded) {
                $locked->refunded_at = now();
            }

            $locked->save();

            if ($restock) {
                $this->restock($locked, $actor instanceof User ? $actor : null);
            }

            // El cupón recupera su uso, sin bajar de 0.
            if ($locked->coupon_id) {
                Coupon::withoutGlobalScopes()->whereKey($locked->coupon_id)->where('used_count', '>', 0)->decrement('used_count');
            }
        });

        return $order->refresh();
    }

    /**
     * Anula una venta de mostrador: la única forma de cancelar un pedido entregado, y solo para el
     * canal mostrador. Solo el owner; exige motivo. Regresa las piezas al inventario (tipo
     * cancelacion) y, como el dinero se devuelve en el momento, queda registrado como reembolsado.
     *
     * @throws InvalidOrderTransitionException
     */
    public function voidCounterSale(Order $order, User $actor, ?string $reason): Order
    {
        $reason = filled($reason) ? trim($reason) : null;

        if ($reason === null) {
            throw new InvalidOrderTransitionException('Escribe el motivo para anular la venta.');
        }

        DB::transaction(function () use ($order, $actor, $reason) {
            // Con el pedido bloqueado: un doble clic encuentra la venta ya anulada y no regresa el stock otra vez.
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->getKey());

            if ($actor->role !== UserRole::Owner) {
                throw new InvalidOrderTransitionException("Solo el dueño puede anular la venta {$locked->number}.");
            }

            $this->statuses->voidCounterSale($locked, $actor, $reason);

            $locked->refresh()->update(['refunded_at' => now()]);

            $this->restock($locked, $actor);
        });

        return $order->refresh();
    }

    /** Regresa las piezas de las líneas normales. Las de sobre pedido nunca descontaron stock. */
    private function restock(Order $order, ?User $user): void
    {
        foreach ($order->items()->where('is_backorder', false)->orderBy('id')->get() as $item) {
            // Con borradas incluidas; si la talla ya no existe en absoluto no hay a dónde regresar la pieza.
            $variant = $item->product_variant_id ? ProductVariant::withTrashed()->find($item->product_variant_id) : null;

            if ($variant === null || $item->quantity < 1) {
                continue;
            }

            $this->stock->change(
                $variant,
                StockMovementType::Cancellation,
                $item->quantity,
                $user,
                "Cancelación del pedido {$order->number}",
                $order,
            );
        }
    }
}
