<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\StockMovements\StockMovementResource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class LatestStockMovements extends TableWidget
{
    protected static ?int $sort = 11;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string|Htmlable|null
    {
        return 'Últimos movimientos de stock';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => StockMovementResource::getEloquentQuery()
                ->reorder()
                ->latest('created_at')
                ->orderByDesc('id')
                ->limit(10))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime(),
                TextColumn::make('productVariant.product.name')
                    ->label('Producto'),
                TextColumn::make('productVariant.size')
                    ->label('Talla'),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "+{$state}" : (string) $state)
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger'),
                TextColumn::make('user.name')
                    ->label('Usuario')
                    ->placeholder('—'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Sin movimientos')
            ->emptyStateDescription('Aún no hay movimientos de stock en esta tienda.');
    }
}
