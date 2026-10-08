<?php

namespace App\Filament\Resources\Brands;

use App\Enums\UserRole;
use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Filament\Resources\Brands\Schemas\BrandForm;
use App\Filament\Resources\Brands\Tables\BrandsTable;
use App\Models\Brand;
use App\Models\Product;
use BackedEnum;
use Closure;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BrandResource extends Resource
{
    protected static ?string $model = Brand::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookmark;

    protected static ?string $modelLabel = 'marca';

    protected static ?string $pluralModelLabel = 'marcas';

    protected static ?int $navigationSort = 3;

    // Las marcas se comparten entre tiendas (no tienen store_id). Cualquier empleado puede crearlas;
    // solo el owner las edita o las borra, y una marca con productos no se borra.
    protected static bool $isScopedToTenant = false;

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->role === UserRole::Owner;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->role === UserRole::Owner;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->role === UserRole::Owner;
    }

    /**
     * Para el «antes» de las acciones de borrar: si la marca tiene productos (de cualquier tienda, también
     * en la papelera) avisa y detiene el borrado. Sin scopes: la marca es de todas las tiendas.
     */
    public static function blockDeleteWhenInUse(): Closure
    {
        return function (Brand $record, $action): void {
            if (Product::withoutGlobalScopes()->where('brand_id', $record->getKey())->exists()) {
                Notification::make()
                    ->danger()
                    ->title('No se puede borrar la marca')
                    ->body("«{$record->name}» tiene productos. Cámbiales la marca antes de borrarla.")
                    ->send();

                $action->halt();
            }
        };
    }

    public static function form(Schema $schema): Schema
    {
        return BrandForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BrandsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBrands::route('/'),
            'create' => CreateBrand::route('/create'),
            'edit' => EditBrand::route('/{record}/edit'),
        ];
    }
}
