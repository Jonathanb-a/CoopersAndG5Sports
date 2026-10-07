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
        return 'Alertas de stock';
    }

    public function table(Table $table): Table
    {
        return $table
            ->description('Modelos agotados (todas las tallas en 0) y modelos con alguna talla en su última pieza.')
            ->query(fn (): Builder => Product::query()
                ->where('products.store_id', Filament::getTenant()?->getKey())
                ->where('products.is_active', true)
                // Agotado por completo, o con alguna talla en 1.
                ->where(fn (Builder $query) => $query
                    ->soldOut()
                    ->orWhereHas('variants', fn (Builder $variants) => $variants->where('stock', 1)))
                ->withSum('variants as total_stock', 'stock')
                ->withCount(['variants as sold_out_count' => fn (Builder $query) => $query->where('stock', 0)])
                ->with(['images', 'variants'])
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
                TextColumn::make('last_piece_sizes')
                    ->label('Tallas con 1 pieza')
                    ->badge()
                    ->color('warning')
                    ->state(fn (Product $record): array => $record->variants->where('stock', 1)->pluck('size')->all())
                    ->placeholder('—'),
                TextColumn::make('alert')
                    ->label('Alerta')
                    ->badge()
                    ->state(fn (Product $record): string => (int) $record->total_stock === 0 ? 'Agotado' : 'Última pieza')
                    ->color(fn (string $state): string => $state === 'Agotado' ? 'danger' : 'warning'),
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
            ->emptyStateDescription('No hay modelos agotados ni tallas en su última pieza.');
    }
}
