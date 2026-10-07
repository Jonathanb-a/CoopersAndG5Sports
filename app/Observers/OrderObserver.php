<?php

namespace App\Observers;

use App\Enums\OrderChannel;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\Store;
use App\Services\StoreUsers;
use App\Services\TelegramNotifier;
use App\Support\TelegramMessage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Number;

class OrderObserver
{
    /**
     * Avisa en la campana del panel y al dueño por Telegram cuando entra un pedido nuevo de la tienda en línea.
     * Las ventas de mostrador las captura el propio personal, así que no generan aviso.
     */
    public function created(Order $order): void
    {
        if ($order->channel !== OrderChannel::Web) {
            return;
        }

        $store = Store::findOrFail($order->store_id);

        app(TelegramNotifier::class)->queue(TelegramMessage::newOrder($order, $store, $order->customer));

        $recipients = StoreUsers::toNotify($store);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->success()
            ->icon('heroicon-o-shopping-cart')
            ->title('Pedido nuevo')
            ->body("Entró el pedido {$order->number} por ".Number::currency((float) $order->total)." en {$store->name}.")
            ->actions([
                Action::make('view')
                    ->label('Ver pedido')
                    ->button()
                    ->url(OrderResource::getUrl('view', ['record' => $order], panel: 'admin', tenant: $store)),
            ])
            ->sendToDatabase($recipients);
    }
}
