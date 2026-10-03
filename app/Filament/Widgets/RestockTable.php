<?php

namespace App\Filament\Widgets;

use App\Enums\StockLevel;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class RestockTable extends TableWidget
{
    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): string|Htmlable|null
    {
        return 'Por resurtir';
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Productos activos en rojo o amarillo, con los agotados primero.')
            ->query(fn (): Builder => Product::query()
                ->where('products.store_id', Filament::getTenant()?->getKey())
                ->where('products.is_active', true)
                ->needsRestock()
                ->withSum('variants as total_stock', 'stock')
                ->withCount(['variants as sold_out_count' => fn (Builder $query) => $query->where('stock', 0)])
                ->with('images')
                ->orderBy('total_stock')
                ->orderByDesc('sold_out_count')
                ->orderBy('products.name'))
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('Foto')
                    ->disk('public')
                    ->state(fn (Product $record): ?string => $record->images->first()?->path),
                TextColumn::make('name')
                    ->label('Producto'),
                TextColumn::make('sold_out_count')
                    ->label('Tallas agotadas')
                    ->badge()
                    ->default(0)
                    ->color(fn ($state): string => (int) $state > 0 ? 'danger' : 'gray'),
                TextColumn::make('total_stock')
                    ->label('Stock total')
                    ->badge()
                    ->default(0)
                    ->color(fn ($state): string => StockLevel::forProduct((int) $state)->getColor()),
            ])
            ->recordUrl(fn (Product $record): string => ProductResource::getUrl('edit', ['record' => $record]))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading('Todo en orden')
            ->emptyStateDescription('No hay productos por resurtir.');
    }
}
