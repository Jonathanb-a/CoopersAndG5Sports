<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ExpiringReservations extends TableWidget
{
    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string|Htmlable|null
    {
        return 'Apartados por vencer';
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Apartados sin pagar que vencen en menos de 12 horas (o que ya vencieron).')
            ->query(fn (): Builder => Order::query()
                ->where('orders.store_id', Filament::getTenant()?->getKey())
                ->where('orders.status', OrderStatus::Reserved->value)
                ->whereNotNull('orders.expires_at')
                ->where('orders.expires_at', '<=', now()->addHours(12))
                ->with('customer')
                ->orderBy('orders.expires_at'))
            ->columns([
                TextColumn::make('number')
                    ->label('Número'),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->placeholder('—'),
                TextColumn::make('total')
                    ->label('Total')
                    ->money(),
                TextColumn::make('expires_at')
                    ->label('Vence')
                    ->since()
                    ->color(fn (Order $record): string => $record->expires_at->isPast() ? 'danger' : 'warning'),
            ])
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Sin apartados por vencer')
            ->emptyStateDescription('Ningún apartado vence en las próximas 12 horas.');
    }
}
