<?php

namespace App\Services;

use App\Enums\DeliveryAttemptResult;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\DeliveryAttempt;
use App\Models\Order;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Entrega a domicilio de los pedidos locales: programar (o reprogramar) la fecha, registrar cada
 * intento y, si ya van varios fallidos, cambiar el pedido a recoger en tienda. Ver CLAUDE.md,
 * «Entrega a domicilio local».
 */
class OrderDeliveryService
{
    /** Con estos intentos fallidos se ofrece cambiar el pedido a recoger en tienda. */
    public const FAILED_ATTEMPTS_FOR_PICKUP = 2;

    public function __construct(private readonly OrderStatusService $statuses) {}

    /**
     * Programa o reprograma la entrega de un pedido local pagado o enviado y lo anota en el historial.
     *
     * @param  string|null  $window  horario en texto libre, por ejemplo "Entre 4 y 6 pm"
     *
     * @throws InvalidOrderTransitionException
     */
    public function schedule(Order $order, CarbonInterface|string $date, ?string $window, User $actor): Order
    {
        $date = Carbon::parse($date)->startOfDay();
        $window = filled($window) ? trim($window) : null;

        if ($date->lt(today())) {
            throw new InvalidOrderTransitionException('La fecha de entrega no puede ser anterior a hoy.');
        }

        return $this->locked($order, $actor, function (Order $locked) use ($date, $window, $actor) {
            if (! self::canSchedule($locked)) {
                throw new InvalidOrderTransitionException(
                    "El pedido {$locked->number} no se puede programar: solo los pedidos de entrega local pagados o enviados."
                );
            }

            $locked->update(['delivery_scheduled_date' => $date, 'delivery_window' => $window]);

            $this->statuses->annotate(
                $locked,
                $actor,
                'Entrega programada para '.$date->format('d/m/Y').($window ? " ({$window})" : '').'.',
            );
        });
    }

    /**
     * Registra un intento de entrega de un pedido local enviado. «Entregado» pasa el pedido a
     * entregado; cualquier otro resultado deja el pedido enviado, sin fecha y por reprogramar.
     *
     * @throws InvalidOrderTransitionException
     */
    public function recordAttempt(Order $order, DeliveryAttemptResult $result, ?string $notes, User $actor, CarbonInterface|string|null $attemptedAt = null): DeliveryAttempt
    {
        $notes = filled($notes) ? trim($notes) : null;
        $attemptedAt = $attemptedAt === null ? now() : Carbon::parse($attemptedAt);

        if ($result === DeliveryAttemptResult::Other && $notes === null) {
            throw new InvalidOrderTransitionException('Escribe una nota para explicar qué pasó en el intento.');
        }

        if ($attemptedAt->gt(now()->endOfDay())) {
            throw new InvalidOrderTransitionException('La fecha del intento no puede ser futura.');
        }

        $attempt = null;

        $this->locked($order, $actor, function (Order $locked) use ($result, $notes, $actor, $attemptedAt, &$attempt) {
            if (! self::canRecordAttempt($locked)) {
                throw new InvalidOrderTransitionException(
                    "El pedido {$locked->number} no admite intentos de entrega: solo los pedidos de entrega local enviados."
                );
            }

            $attempt = $locked->deliveryAttempts()->create([
                'user_id' => $actor->getKey(),
                'result' => $result,
                'notes' => $notes,
                'attempted_at' => $attemptedAt,
            ]);

            if ($result->failed()) {
                $locked->update(['delivery_scheduled_date' => null, 'delivery_window' => null]);

                return;
            }

            $this->statuses->transition($locked, OrderStatus::Delivered, $actor, 'Entrega a domicilio confirmada.');
        });

        return $attempt;
    }

    /**
     * Cambia un pedido local con varios intentos fallidos a recoger en tienda. Solo cambia el método
     * de entrega: los totales (incluido el envío ya cobrado) se quedan como están.
     *
     * @throws InvalidOrderTransitionException
     */
    public function switchToPickup(Order $order, User $actor): Order
    {
        return $this->locked($order, $actor, function (Order $locked) use ($actor) {
            if (! self::canSwitchToPickup($locked)) {
                throw new InvalidOrderTransitionException(
                    "El pedido {$locked->number} no se puede cambiar a recoger en tienda: solo los pedidos locales con "
                    .self::FAILED_ATTEMPTS_FOR_PICKUP.' o más intentos de entrega fallidos.'
                );
            }

            $failed = $locked->failedDeliveryAttempts();

            $locked->update([
                'delivery_method' => DeliveryMethod::Pickup,
                'delivery_scheduled_date' => null,
                'delivery_window' => null,
            ]);

            $this->statuses->annotate(
                $locked,
                $actor,
                "Se cambió la entrega a recoger en tienda después de {$failed} intentos de entrega fallidos.",
            );
        });
    }

    public static function canSchedule(Order $order): bool
    {
        return $order->delivery_method === DeliveryMethod::Local
            && in_array($order->status, [OrderStatus::Paid, OrderStatus::Shipped], true);
    }

    public static function canRecordAttempt(Order $order): bool
    {
        return $order->delivery_method === DeliveryMethod::Local && $order->status === OrderStatus::Shipped;
    }

    public static function canSwitchToPickup(Order $order): bool
    {
        return self::canSchedule($order) && $order->failedDeliveryAttempts() >= self::FAILED_ATTEMPTS_FOR_PICKUP;
    }

    /**
     * Corre el cambio con el pedido bloqueado y solo si quien lo hace atiende esa tienda: el owner
     * todas, el staff las suyas.
     */
    private function locked(Order $order, User $actor, Closure $callback): Order
    {
        DB::transaction(function () use ($order, $actor, $callback) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->getKey());

            if (! $actor->canAccessTenant($locked->store)) {
                throw new InvalidOrderTransitionException("El pedido {$locked->number} es de otra tienda.");
            }

            $callback($locked);
        });

        return $order->refresh()->unsetRelation('deliveryAttempts');
    }
}
