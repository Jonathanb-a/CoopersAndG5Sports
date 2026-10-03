<?php

namespace App\Filament\Widgets;

use App\Enums\SalesPeriod;
use App\Services\SalesReport;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;

class TopProducts extends TableWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string|Htmlable|null
    {
        return 'Más vendidos';
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Top 10 por piezas vendidas en el periodo seleccionado.')
            ->records(function (): array {
                $store = Filament::getTenant();

                if ($store === null) {
                    return [];
                }

                $period = SalesPeriod::fromFilters($this->pageFilters);
                $rows = app(SalesReport::class)->topProducts($store, ...$period->range());

                // Cada fila necesita una llave única: el nombre del producto.
                return array_column($rows, null, 'name');
            })
            ->columns([
                TextColumn::make('name')
                    ->label('Producto'),
                TextColumn::make('pieces')
                    ->label('Piezas vendidas')
                    ->badge()
                    ->color('success'),
                TextColumn::make('revenue')
                    ->label('Importe')
                    ->money(),
            ])
            ->paginated(false)
            ->emptyStateHeading('Sin ventas')
            ->emptyStateDescription('No hay ventas en este periodo.');
    }
}
