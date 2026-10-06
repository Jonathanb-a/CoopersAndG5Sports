<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrdersTable
{
    public const BACKORDER = 'Sobre pedido';

    public const CANCELLATION_REQUESTED = 'Solicitó cancelar';

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('customer')
                ->withExists(['items as has_backorder' => fn (Builder $items) => $items->where('is_backorder', true)]))
            ->columns([
                TextColumn::make('number')
                    ->label('Número')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime()
                    ->sortable(),
                // Sin cliente es una venta de mostrador. La búsqueda cubre nombre, correo y teléfono.
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->placeholder('Mostrador')
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('customer', fn (Builder $customer) => $customer
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%"))),
                TextColumn::make('total')
                    ->label('Total')
                    ->money()
                    ->sortable(),
                // "Enviado" se muestra "Listo para recoger" o "En camino" según la entrega.
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state, Order $record) => $record->statusLabel()),
                TextColumn::make('marks')
                    ->label('Avisos')
                    ->state(fn (Order $record) => self::marks($record))
                    ->badge()
                    ->color(fn (string $state) => $state === self::BACKORDER ? 'info' : 'danger')
                    ->placeholder('—'),
                TextColumn::make('delivery_method')
                    ->label('Entrega')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('channel')
                    ->label('Canal')
                    ->badge(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(OrderStatus::class),
                SelectFilter::make('delivery_method')
                    ->label('Método de entrega')
                    ->options(DeliveryMethod::class),
                SelectFilter::make('channel')
                    ->label('Canal')
                    ->options(OrderChannel::class),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Desde'),
                        DatePicker::make('until')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(fn (array $data) => array_filter([
                        ($data['from'] ?? null) ? 'Desde '.date('d/m/Y', strtotime($data['from'])) : null,
                        ($data['until'] ?? null) ? 'Hasta '.date('d/m/Y', strtotime($data['until'])) : null,
                    ])),
                Filter::make('backorder')
                    ->label('Solo sobre pedido')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereHas('items', fn (Builder $items) => $items->where('is_backorder', true))),
                Filter::make('cancellation_requested')
                    ->label('Solicitud de cancelación')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNotNull('cancellation_requested_at')),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Sin pedidos')
            ->emptyStateDescription('Aquí aparecerán los pedidos de la tienda en línea y del mostrador.');
    }

    /** @return list<string> */
    public static function marks(Order $order): array
    {
        return array_values(array_filter([
            $order->has_backorder ? self::BACKORDER : null,
            // La solicitud deja de importar cuando el pedido ya se canceló.
            $order->cancellation_requested_at && $order->status !== OrderStatus::Cancelled ? self::CANCELLATION_REQUESTED : null,
        ]));
    }
}
