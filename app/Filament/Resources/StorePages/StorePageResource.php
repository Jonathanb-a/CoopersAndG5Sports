<?php

namespace App\Filament\Resources\StorePages;

use App\Enums\UserRole;
use App\Filament\Resources\StorePages\Pages\CreateStorePage;
use App\Filament\Resources\StorePages\Pages\EditStorePage;
use App\Filament\Resources\StorePages\Pages\ListStorePages;
use App\Filament\Resources\StorePages\Schemas\StorePageForm;
use App\Filament\Resources\StorePages\Tables\StorePagesTable;
use App\Models\StorePage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Páginas de contenido y legales de la tienda actual. El owner y el staff de la tienda editan el
 * contenido; solo el owner publica o despublica.
 */
class StorePageResource extends Resource
{
    protected static ?string $model = StorePage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'página';

    protected static ?string $pluralModelLabel = 'páginas';

    protected static ?int $navigationSort = 9;

    protected static ?string $recordTitleAttribute = 'title';

    public static function canPublish(): bool
    {
        return auth()->user()?->role === UserRole::Owner;
    }

    /**
     * Quien no es owner no cambia la publicación, aunque mande el campo por Livewire: una página
     * nueva nace sin publicar y una existente conserva lo que tenía.
     */
    public static function guardPublication(array $data, ?StorePage $record = null): array
    {
        if (! self::canPublish()) {
            $data['is_published'] = $record?->is_published ?? false;
        }

        return $data;
    }

    public static function form(Schema $schema): Schema
    {
        return StorePageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StorePagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStorePages::route('/'),
            'create' => CreateStorePage::route('/create'),
            'edit' => EditStorePage::route('/{record}/edit'),
        ];
    }
}
