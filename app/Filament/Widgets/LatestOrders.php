<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class LatestOrders extends TableWidget
{
    public const LIMIT = 8;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string|Htmlable|null
    {
        return 'Últimos pedidos';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Order::query()
                ->where('orders.store_id', Filament::getTenant()?->getKey())
                ->with('customer')
                ->latest('created_at')
                ->orderByDesc('id')
                ->limit(self::LIMIT))
            ->columns([
                TextColumn::make('number')
                    ->label('Número'),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->placeholder('Mostrador'),
                TextColumn::make('total')
                    ->label('Total')
                    ->money(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (Order $record): string => $record->statusLabel()),
                TextColumn::make('channel')
                    ->label('Canal')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime(),
            ])
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->paginated(false)
            ->emptyStateHeading('Sin pedidos')
            ->emptyStateDescription('Aún no hay pedidos en esta tienda.');
    }
}
