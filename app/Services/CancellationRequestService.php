<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreGuard;
use App\Support\TelegramMessage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Solicitudes de cancelación del cliente. Un pedido pagado no lo cancela el cliente: lo solicita
 * y el personal decide (cancela con OrderCancellationService o rechaza la solicitud).
 */
class CancellationRequestService
{
    public function __construct(private readonly OrderStatusService $statuses) {}

    /** El cliente puede solicitar cancelar un pedido pagado que aún no sale y que no tiene ya una solicitud. */
    public static function canRequest(Order $order): bool
    {
        return $order->status === OrderStatus::Paid && $order->cancellation_requested_at === null;
    }

    /** Solicitud en revisión: el pedido sigue pagado y nadie la ha atendido. */
    public static function isPending(Order $order): bool
    {
        return $order->status === OrderStatus::Paid && $order->cancellation_requested_at !== null;
    }

    /**
     * Registra la solicitud. No cancela nada: guarda la fecha y la nota, lo anota en el historial
     * y avisa en la campana del panel al personal de la tienda del pedido.
     *
     * @throws InvalidOrderTransitionException si el pedido no está pagado o ya tiene una solicitud
     */
    public function request(Order $order, Customer $customer, ?string $note = null): Order
    {
        $note = filled($note) ? trim($note) : null;

        DB::transaction(function () use ($order, $customer, $note) {
            // Con el pedido bloqueado: un doble clic no deja dos solicitudes ni dos avisos.
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->getKey());

            StoreGuard::authorize($locked, $customer);

            if ($locked->cancellation_requested_at !== null && $locked->status === OrderStatus::Paid) {
                throw new InvalidOrderTransitionException("El pedido {$locked->number} ya tiene una solicitud de cancelación en revisión.");
            }

            if (! self::canRequest($locked)) {
                throw new InvalidOrderTransitionException(
                    "El pedido {$locked->number} está «{$locked->statusLabel()}»: solo se puede solicitar la cancelación de un pedido pagado que aún no sale."
                );
            }

            $locked->update(['cancellation_requested_at' => now(), 'cancellation_request_note' => $note]);

            $this->statuses->annotate($locked, $customer, 'El cliente solicitó cancelar el pedido.'.($note ? " Motivo: {$note}" : ''));

            $this->notifyStaff($locked, $customer, $note);
        });

        return $order->refresh();
    }

    /**
     * El personal rechaza la solicitud: se limpia y queda la nota en el historial. El pedido sigue su curso.
     *
     * @throws InvalidOrderTransitionException si el pedido no tiene una solicitud
     */
    public function reject(Order $order, User $user, ?string $note = null): Order
    {
        $note = filled($note) ? trim($note) : null;

        DB::transaction(function () use ($order, $user, $note) {
            $locked = Order::withoutGlobalScopes()->lockForUpdate()->findOrFail($order->getKey());

            StoreGuard::authorize($locked, $user);

            if ($locked->cancellation_requested_at === null) {
                throw new InvalidOrderTransitionException("El pedido {$locked->number} no tiene una solicitud de cancelación.");
            }

            $locked->update(['cancellation_requested_at' => null, 'cancellation_request_note' => null]);

            $this->statuses->annotate($locked, $user, 'Solicitud de cancelación rechazada.'.($note ? " {$note}" : ''));
        });

        return $order->refresh();
    }

    private function notifyStaff(Order $order, Customer $customer, ?string $note): void
    {
        $store = Store::findOrFail($order->store_id);

        app(TelegramNotifier::class)->queue(TelegramMessage::cancellationRequest($order, $store, $customer, $note));

        $recipients = StoreUsers::toNotify($store);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->warning()
            ->icon('heroicon-o-exclamation-circle')
            ->title('Solicitud de cancelación')
            ->body("{$customer->name} pidió cancelar el pedido {$order->number} (ya pagado) en {$store->name}.".($note ? " Motivo: {$note}" : ''))
            ->actions([
                Action::make('view')
                    ->label('Ver pedido')
                    ->button()
                    ->url(OrderResource::getUrl('view', ['record' => $order], panel: 'admin', tenant: $store)),
            ])
            ->sendToDatabase($recipients);
    }
}
