<?php

namespace App\Filament\Widgets;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
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
 * entrega a domicilio sin fecha o un pedido pagado que todavía no sale.
 */
class OrdersNeedingAction extends TableWidget
{
    public const CANCELLATION = 'Solicitó cancelar';

    public const NO_DELIVERY_DATE = 'Entrega sin fecha';

    public const NOT_SHIPPED = 'Pagado sin enviar';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string|Htmlable|null
    {
        return 'Pedidos que requieren acción';
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Solicitudes de cancelación pendientes, entregas a domicilio sin fecha y pedidos pagados sin enviar.')
            ->query(fn (): Builder => Order::query()
                ->where('orders.store_id', Filament::getTenant()?->getKey())
                // Las solicitudes pendientes y los locales pagados sin fecha son pedidos pagados; solo
                // faltan los locales ya enviados que se quedaron sin fecha (por reprogramar).
                ->where(fn (Builder $query) => $query
                    ->where('orders.status', OrderStatus::Paid->value)
                    ->orWhere(fn (Builder $local) => $local
                        ->where('orders.status', OrderStatus::Shipped->value)
                        ->where('orders.delivery_method', DeliveryMethod::Local->value)
                        ->whereNull('orders.delivery_scheduled_date')))
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
                        self::NO_DELIVERY_DATE => 'warning',
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
            $paid ? self::NOT_SHIPPED : null,
        ]));
    }
}
