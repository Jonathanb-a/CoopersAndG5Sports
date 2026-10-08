<?php

namespace App\Services;

use App\Enums\CancelledBy;
use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Support\StoreGuard;
use Illuminate\Support\Facades\DB;

/**
 * Único lugar que cambia orders.status. Valida la transición (ver OrderStatus::nextStatuses y
 * CLAUDE.md), guarda las fechas y datos que le tocan a cada estado y deja el renglón del historial,
 * todo en la misma transacción.
 *
 * Cancelar aquí solo cambia el estado y sus datos: regresar el stock y el uso del cupón es parte del
 * flujo de cancelación, que llama a este servicio.
 */
class OrderStatusService
{
    /**
     * Cambia el estado de un pedido. En $data van los datos del paso: la referencia de pago al marcar
     * pagado; la paquetería y la guía al marcar enviado.
     *
     * @param  User|Customer|null  $actor  quién lo hace: personal del panel, el cliente, o null si es el sistema
     * @param  string|null  $note  nota para el historial (al cancelar es también el motivo)
     * @param  array{payment_reference?: ?string, tracking_carrier?: ?string, tracking_number?: ?string}  $data  datos del paso
     *
     * @throws InvalidOrderTransitionException
     */
    public function transition(Order $order, OrderStatus $to, User|Customer|null $actor = null, ?string $note = null, array $data = []): Order
    {
        $note = filled($note) ? trim($note) : null;

        DB::transaction(function () use ($order, $to, $actor, $note, $data) {
            // Con la fila bloqueada: dos personas no pueden mover el mismo pedido a la vez.
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->getKey());

            StoreGuard::authorize($locked, $actor);
            $from = $locked->status;

            if (! $from->canTransitionTo($to)) {
                throw new InvalidOrderTransitionException(
                    "El pedido {$locked->number} está «{$from->getLabel()}» y no puede pasar a «{$to->getLabel()}»."
                );
            }

            $locked->status = $to;

            match ($to) {
                OrderStatus::Paid => $this->markPaid($locked, $data),
                OrderStatus::Shipped => $this->markShipped($locked, $data),
                OrderStatus::Delivered => $locked->delivered_at = now(),
                OrderStatus::Cancelled => $this->markCancelled($locked, $actor, $note),
                default => null,
            };

            Order::withStatusChange(fn () => $locked->save());

            $this->record($locked, $from, $to, $actor, $note);
        });

        return $order->refresh();
    }

    /** Primer renglón del historial de un pedido recién creado (sin estado anterior). */
    public function recordInitial(Order $order, User|Customer|null $actor = null, ?string $note = null): OrderStatusHistory
    {
        return $this->record($order, null, $order->status, $actor, $note);
    }

    /** Deja una anotación en el historial sin cambiar el estado (por ejemplo, que se dio más tiempo para pagar). */
    public function annotate(Order $order, User|Customer|null $actor, string $note): OrderStatusHistory
    {
        StoreGuard::authorize($order, $actor);

        return $this->record($order, $order->status, $order->status, $actor, trim($note));
    }

    /**
     * Única excepción a «cancelado es final»: un pedido que canceló el sistema (venció el apartado) y
     * que nunca se pagó puede volver a apartado. Aquí solo se cambia el estado y se limpia la
     * cancelación; volver a apartar las piezas es trabajo de OrderReservationService::reactivate().
     *
     * @throws InvalidOrderTransitionException
     */
    public function reactivate(Order $order, User|Customer|null $actor = null, ?string $note = null): Order
    {
        DB::transaction(function () use ($order, $actor, $note) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->getKey());

            StoreGuard::authorize($locked, $actor);

            if (! self::canReactivate($locked)) {
                throw new InvalidOrderTransitionException(
                    "El pedido {$locked->number} no se puede reactivar: solo los pedidos que canceló el sistema y que nunca se pagaron."
                );
            }

            $locked->forceFill([
                'status' => OrderStatus::Reserved,
                'cancelled_at' => null,
                'cancel_reason' => null,
                'cancelled_by' => null,
            ]);

            Order::withStatusChange(fn () => $locked->save());

            $this->record($locked, OrderStatus::Cancelled, OrderStatus::Reserved, $actor, filled($note) ? trim($note) : null);
        });

        return $order->refresh();
    }

    /**
     * Única excepción a «entregado es final»: anular una venta de mostrador. Aquí solo se cambia el
     * estado y se guardan los datos de la cancelación; regresar las piezas es trabajo de
     * OrderCancellationService::voidCounterSale().
     *
     * @throws InvalidOrderTransitionException
     */
    public function voidCounterSale(Order $order, User $actor, string $reason): Order
    {
        DB::transaction(function () use ($order, $actor, $reason) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->getKey());

            StoreGuard::authorize($locked, $actor);

            if (! self::canVoid($locked)) {
                throw new InvalidOrderTransitionException(
                    "El pedido {$locked->number} no se puede anular: solo las ventas de mostrador entregadas."
                );
            }

            $locked->status = OrderStatus::Cancelled;
            $this->markCancelled($locked, $actor, trim($reason));

            Order::withStatusChange(fn () => $locked->save());

            $this->record($locked, OrderStatus::Delivered, OrderStatus::Cancelled, $actor, 'Venta anulada: '.trim($reason));
        });

        return $order->refresh();
    }

    public static function canVoid(Order $order): bool
    {
        return $order->channel === OrderChannel::Counter && $order->status === OrderStatus::Delivered;
    }

    public static function canReactivate(Order $order): bool
    {
        return $order->status === OrderStatus::Cancelled
            && $order->cancelled_by === CancelledBy::System
            && $order->paid_at === null;
    }

    private function markPaid(Order $order, array $data): void
    {
        $order->paid_at = now();

        if (filled($data['payment_reference'] ?? null)) {
            $order->payment_reference = trim($data['payment_reference']);
        }
    }

    /** Un envío nacional no sale sin paquetería y número de guía. */
    private function markShipped(Order $order, array $data): void
    {
        foreach (['tracking_carrier', 'tracking_number'] as $field) {
            if (filled($data[$field] ?? null)) {
                $order->{$field} = trim($data[$field]);
            }
        }

        if ($order->delivery_method === DeliveryMethod::National && (blank($order->tracking_carrier) || blank($order->tracking_number))) {
            throw new InvalidOrderTransitionException(
                "El pedido {$order->number} es de envío nacional: captura la paquetería y el número de guía para marcarlo enviado."
            );
        }
    }

    private function markCancelled(Order $order, User|Customer|null $actor, ?string $note): void
    {
        $order->cancelled_at = now();
        $order->cancel_reason = $note;
        $order->cancelled_by = match (true) {
            $actor instanceof Customer => CancelledBy::Customer,
            $actor instanceof User => CancelledBy::Admin,
            default => CancelledBy::System,
        };
    }

    private function record(Order $order, ?OrderStatus $from, OrderStatus $to, User|Customer|null $actor, ?string $note): OrderStatusHistory
    {
        return OrderStatusHistory::create([
            'order_id' => $order->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $actor instanceof User ? $actor->getKey() : null,
            'customer_id' => $actor instanceof Customer ? $actor->getKey() : null,
            'note' => $note,
        ]);
    }
}
