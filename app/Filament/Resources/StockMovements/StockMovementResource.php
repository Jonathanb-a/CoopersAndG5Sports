<?php

namespace App\Filament\Resources\StockMovements;

use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\StockMovement;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'movimiento de stock';

    protected static ?string $pluralModelLabel = 'movimientos de stock';

    protected static ?string $navigationLabel = 'Movimientos de stock';

    protected static ?int $navigationSort = 4;

    // stock_movements no tiene store_id: el filtro por tienda se hace en getEloquentQuery().
    protected static bool $isScopedToTenant = false;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return StockMovementsTable::configure($table);
    }

    /**
     * Movimientos de las variantes de productos de la tienda actual. Se consulta sin
     * los scopes de soft delete para que el historial siga visible si se borra un
     * producto o una talla.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('product_variant_id', fn ($query) => $query
                ->select('product_variants.id')
                ->from('product_variants')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where('products.store_id', Filament::getTenant()?->getKey()))
            ->with([
                'user',
                'productVariant' => fn ($query) => $query->withTrashed()->with([
                    'product' => fn ($query) => $query->withTrashed(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }
}
