<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\UserRole;
use App\Models\Product;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Producto')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set, string $operation) => self::fillSlug($get, $set, $operation)),
                        TextInput::make('color')
                            ->label('Color')
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set, string $operation) => self::fillSlug($get, $set, $operation)),
                        TextInput::make('slug')
                            ->label('Slug')
                            ->helperText('Se genera automáticamente a partir del nombre y el color.')
                            ->readOnly()
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule) => $rule->where('store_id', Filament::getTenant()?->getKey()),
                            )
                            ->validationMessages(['unique' => 'Ya existe un producto con este nombre y color en la tienda.'])
                            ->columnSpanFull(),
                        Select::make('category_id')
                            ->label('Categoría')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('brand_id')
                            ->label('Marca')
                            ->relationship('brand', 'name')
                            ->searchable()
                            ->preload(),
                        Textarea::make('description')
                            ->label('Descripción')
                            ->rows(4)
                            ->columnSpanFull(),
                        TextInput::make('price')
                            ->label('Precio')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99999999.99)
                            ->step(0.01)
                            ->prefix('$'),
                        TextInput::make('cost')
                            ->label('Costo')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(99999999.99)
                            ->step(0.01)
                            ->prefix('$')
                            ->visible(fn (): bool => auth()->user()?->role === UserRole::Owner),
                        Toggle::make('allow_backorder')
                            ->label('Permitir pedido sin stock')
                            ->live(),
                        TextInput::make('backorder_days')
                            ->label('Días de espera')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->visible(fn (Get $get): bool => (bool) $get('allow_backorder'))
                            ->dehydratedWhenHidden()
                            ->dehydrateStateUsing(fn ($state, Get $get) => $get('allow_backorder') ? $state : null),
                        Toggle::make('is_active')
                            ->label('Activo')
                            ->default(true),
                    ]),

                Section::make('Tallas')
                    ->columnSpanFull()
                    ->description('El stock se captura solo al crear el producto. Después se ajusta con movimientos de inventario.')
                    ->schema([
                        Repeater::make('variants')
                            ->hiddenLabel()
                            ->relationship()
                            ->columns(3)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Agregar talla')
                            ->deletable(fn (string $operation): bool => $operation === 'create')
                            ->itemLabel(fn (array $state): ?string => $state['size'] ?? null)
                            ->schema([
                                TextInput::make('size')
                                    ->label('Talla')
                                    ->required()
                                    ->maxLength(255)
                                    ->distinct(),
                                TextInput::make('sku')
                                    ->label('SKU')
                                    ->maxLength(255),
                                TextInput::make('stock')
                                    ->label('Stock')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->default(0)
                                    ->disabled(fn (string $operation): bool => $operation === 'edit'),
                            ]),
                    ]),

                Section::make('Fotos')
                    ->columnSpanFull()
                    ->schema([
                        FileUpload::make('photos')
                            ->hiddenLabel()
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->disk('public')
                            ->directory('products')
                            ->visibility('public')
                            ->dehydrated(false)
                            ->loadStateFromRelationshipsUsing(function (FileUpload $component, ?Product $record): void {
                                $component->state($record?->images->pluck('path')->all() ?? []);
                            })
                            ->saveRelationshipsUsing(function (Product $record, $state): void {
                                $paths = array_values(array_filter((array) $state));

                                $removed = $record->images()->whereNotIn('path', $paths)->get();
                                $removed->each->delete();
                                Storage::disk('public')->delete($removed->pluck('path')->all());

                                foreach ($paths as $position => $path) {
                                    $record->images()->updateOrCreate(['path' => $path], ['position' => $position]);
                                }
                            }),
                    ]),
            ]);
    }

    private static function fillSlug(Get $get, Set $set, string $operation): void
    {
        if ($operation === 'create') {
            $set('slug', Str::slug(trim($get('name').' '.$get('color'))));
        }
    }
}
