<?php

namespace App\Filament\Widgets;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pedidos de la tienda que esperan algo del personal: una solicitud de cancelación sin atender, una
 * entrega a domicilio o una recogida sin fecha, un pedido pagado que todavía no sale o un pedido en
 * efectivo que pasó su fecha sin entregarse.
 */
class OrdersNeedingAction extends TableWidget
{
    public const CANCELLATION = 'Solicitó cancelar';

    public const NO_DELIVERY_DATE = 'Entrega sin fecha';

    public const NO_PICKUP_DATE = 'Recogida sin fecha';

    public const NOT_SHIPPED = 'Pagado sin enviar';

    public const CASH_OVERDUE = 'Efectivo sin entregar';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string|Htmlable|null
    {
        return 'Pedidos que requieren acción';
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Solicitudes de cancelación pendientes, entregas a domicilio y recogidas sin fecha, pedidos pagados sin enviar y pedidos en efectivo vencidos sin entregar.')
            ->query(fn (): Builder => Order::query()
                ->where('orders.store_id', Filament::getTenant()?->getKey())
                // Las solicitudes pendientes, los locales pagados sin fecha y las recogidas sin fecha son pedidos
                // pagados. Faltan los locales ya enviados sin fecha (por reprogramar) y los de efectivo vencidos.
                ->where(fn (Builder $query) => $query
                    ->where('orders.status', OrderStatus::Paid->value)
                    ->orWhere(fn (Builder $local) => $local
                        ->where('orders.status', OrderStatus::Shipped->value)
                        ->where('orders.delivery_method', DeliveryMethod::Local->value)
                        ->whereNull('orders.delivery_scheduled_date'))
                    ->orWhere(fn (Builder $cash) => $cash
                        ->where('orders.status', OrderStatus::Shipped->value)
                        ->where('orders.payment_method', PaymentMethod::Cash->value)
                        ->whereNotNull('orders.expires_at')
                        ->where('orders.expires_at', '<=', now())))
                ->with('customer')
                // Primero lo que pidió cancelar el cliente; después, lo que lleva más tiempo pagado.
                ->orderByRaw('CASE WHEN orders.cancellation_requested_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('orders.paid_at')
                ->orderBy('orders.id'))
            ->columns([
                TextColumn::make('number')
                    ->label('Número'),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->placeholder('Mostrador'),
                TextColumn::make('reasons')
                    ->label('Qué falta')
                    ->badge()
                    ->state(fn (Order $record): array => self::reasons($record))
                    ->color(fn (string $state): string => match ($state) {
                        self::CANCELLATION => 'danger',
                        self::NO_DELIVERY_DATE, self::NO_PICKUP_DATE, self::CASH_OVERDUE => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('total')
                    ->label('Total')
                    ->money(),
                TextColumn::make('paid_at')
                    ->label('Pagado')
                    ->since()
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Todo al día')
            ->emptyStateDescription('Ningún pedido espera una acción.');
    }

    /** @return list<string> */
    public static function reasons(Order $order): array
    {
        $paid = $order->status === OrderStatus::Paid;

        return array_values(array_filter([
            $paid && $order->cancellation_requested_at !== null ? self::CANCELLATION : null,
            $order->delivery_method === DeliveryMethod::Local && $order->delivery_scheduled_date === null ? self::NO_DELIVERY_DATE : null,
            $paid && $order->delivery_method === DeliveryMethod::Pickup && $order->delivery_scheduled_date === null ? self::NO_PICKUP_DATE : null,
            $paid ? self::NOT_SHIPPED : null,
            self::cashOverdue($order) ? self::CASH_OVERDUE : null,
        ]));
    }

    /** En efectivo, confirmado o enviado, con la fecha ya pasada y sin entregar. */
    private static function cashOverdue(Order $order): bool
    {
        return $order->paysInCash()
            && in_array($order->status, [OrderStatus::Paid, OrderStatus::Shipped], true)
            && $order->expires_at !== null
            && $order->expires_at->lte(now());
    }
}
