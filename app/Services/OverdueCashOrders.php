<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\Store;
use App\Support\TelegramMessage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Pedidos en efectivo ya confirmados (pagado o enviado) que pasaron su fecha sin entregarse. No se
 * cancelan solos: se le avisa al dueño, en la campana del panel y por Telegram, una sola vez.
 */
class OverdueCashOrders
{
    public const TITLE = 'Pedido en efectivo sin entregar';

    /** Confirmados en efectivo, con la fecha pasada y sin aviso todavía. */
    public function query(): Builder
    {
        return Order::withoutGlobalScopes()
            ->where('payment_method', 'cash')
            ->whereIn('status', [OrderStatus::Paid->value, OrderStatus::Shipped->value])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereNull('cash_overdue_notified_at');
    }

    /** Avisa de un pedido. False si otra corrida ya lo avisó (el aviso se reclama de forma atómica). */
    public function notify(Order $order): bool
    {
        $claimed = $this->query()->whereKey($order->getKey())->update(['cash_overdue_notified_at' => now()]);

        if ($claimed !== 1) {
            return false;
        }

        $order->refresh()->loadMissing('customer');
        $store = Store::findOrFail($order->store_id);

        app(TelegramNotifier::class)->queue(TelegramMessage::cashOrderOverdue($order, $store, $order->customer));

        $recipients = StoreUsers::toNotify($store);

        if ($recipients->isNotEmpty()) {
            Notification::make()
                ->warning()
                ->icon('heroicon-o-banknotes')
                ->title(self::TITLE)
                ->body("El pedido {$order->number} de ".($order->customer?->name ?? 'un cliente').' ('.Number::currency((float) $order->total)." por cobrar) en {$store->name} pasó su fecha sin entregarse.")
                ->actions([
                    Action::make('view')
                        ->label('Ver pedido')
                        ->button()
                        ->url(OrderResource::getUrl('view', ['record' => $order], panel: 'admin', tenant: $store)),
                ])
                ->sendToDatabase($recipients);
        }

        return true;
    }
}
