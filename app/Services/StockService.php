<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreGuard;
use App\Support\TelegramMessage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockService
{
    /**
     * Único punto de entrada para cambiar el stock de una variante.
     *
     * $quantity es el cambio con signo (positivo suma, negativo resta).
     * Todo ocurre en una transacción con la fila bloqueada; si el stock
     * quedaría negativo se lanza InsufficientStockException y no cambia nada.
     */
    public function change(
        ProductVariant $variant,
        StockMovementType $type,
        int $quantity,
        ?User $user = null,
        ?string $notes = null,
        ?Order $order = null,
    ): StockMovement {
        if ($quantity === 0) {
            throw new InvalidArgumentException('La cantidad del movimiento no puede ser cero.');
        }

        // Un movimiento ligado a un pedido solo puede tocar piezas de la tienda de ese pedido.
        if ($order !== null) {
            StoreGuard::assertSameStore($variant, $order);
        }

        $movement = DB::transaction(function () use ($variant, $type, $quantity, $user, $notes, $order) {
            // Con borradas incluidas: al cancelar un pedido las piezas regresan aunque la talla esté en la papelera.
            $locked = ProductVariant::withTrashed()->lockForUpdate()->findOrFail($variant->getKey());

            $newStock = $locked->stock + $quantity;

            if ($newStock < 0) {
                throw InsufficientStockException::for($locked->stock, $quantity);
            }

            $locked->stock = $newStock;
            $locked->save();

            return StockMovement::create([
                'product_variant_id' => $locked->id,
                'type' => $type,
                'quantity' => $quantity,
                'user_id' => $user?->getKey(),
                'order_id' => $order?->getKey(),
                'notes' => $notes,
            ]);
        });

        $variant->refresh();

        // Solo se llega a 0 desde un stock positivo (la cantidad nunca es cero).
        if ($variant->stock === 0) {
            $this->notifySoldOut($variant);
        }

        return $movement;
    }

    /**
     * Avisa en la campana del panel a los usuarios de la tienda (los de store_user y los
     * owner, que ven todas las tiendas) que una talla se agotó, y si con ella se agotó todo
     * el producto.
     */
    private function notifySoldOut(ProductVariant $variant): void
    {
        // Sin scopes: debe funcionar aunque el producto esté en la papelera o no haya tienda actual.
        $product = Product::withoutGlobalScopes()->findOrFail($variant->product_id);
        $store = Store::findOrFail($product->store_id);

        $productSoldOut = ! ProductVariant::query()->where('product_id', $product->id)->where('stock', '>', 0)->exists();

        // Al dueño por Telegram solo cuando se agota el modelo completo. A este punto se llega
        // únicamente cuando una talla pasa de tener piezas a 0, así que no se repite mientras siga
        // agotado y vuelve a avisar si se repone y se agota otra vez.
        if ($productSoldOut) {
            app(TelegramNotifier::class)->queue(TelegramMessage::soldOut($product, $store));
        }

        $recipients = StoreUsers::toNotify($store);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->danger()
            ->icon('heroicon-o-exclamation-triangle')
            ->title($productSoldOut ? 'Producto agotado' : 'Talla agotada')
            ->body($productSoldOut
                ? "Con la talla {$variant->size} se agotó todo el producto «{$product->name}» en {$store->name}: ya no quedan piezas en ninguna talla."
                : "La talla {$variant->size} de «{$product->name}» en {$store->name} se quedó sin piezas.")
            ->actions([
                Action::make('view')
                    ->label('Ver producto')
                    ->button()
                    ->url(ProductResource::getUrl('edit', ['record' => $product], panel: 'admin', tenant: $store)),
            ])
            ->sendToDatabase($recipients);
    }
}
