<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\StockLevel;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStockException;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\StockService;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Exists;
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
                            // En el servidor: la categoría tiene que ser de esta tienda, aunque inventen el ID.
                            ->exists(table: 'categories', column: 'id', modifyRuleUsing: fn (Exists $rule) => $rule->where('store_id', Filament::getTenant()?->getKey()))
                            ->validationMessages(['exists' => 'Elige una categoría de esta tienda.'])
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
                    ->description('El stock inicial se captura solo al crear el producto y se registra como una entrada. Después se cambia con la acción «Ajustar stock» de cada talla.')
                    ->schema([
                        Repeater::make('variants')
                            ->label('Tallas')
                            ->hiddenLabel()
                            ->relationship()
                            ->columns(4)
                            ->minItems(1)
                            ->defaultItems(1)
                            ->addActionLabel('Agregar talla')
                            ->deletable(fn (string $operation): bool => $operation === 'create')
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => [...$data, 'stock' => 0])
                            ->extraItemActions([self::adjustStockAction()])
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
                                Text::make(fn (Get $get): string => StockLevel::forVariant((int) $get('stock'))->getLabel())
                                    ->badge()
                                    ->color(fn (Get $get): string => StockLevel::forVariant((int) $get('stock'))->getColor())
                                    ->visible(fn (string $operation): bool => $operation === 'edit'),
                            ]),
                    ]),

                Section::make('Fotos')
                    ->columnSpanFull()
                    ->schema([
                        FileUpload::make('photos')
                            ->label('Fotos')
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

    private static function adjustStockAction(): Action
    {
        return Action::make('adjustStock')
            ->label('Ajustar stock')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->iconButton()
            ->visible(fn (array $arguments, Repeater $component): bool => self::variantFor($component, $arguments) !== null)
            ->modalHeading(fn (array $arguments, Repeater $component): string => 'Ajustar stock · talla '.self::variantFor($component, $arguments)?->size)
            ->modalSubmitActionLabel('Guardar')
            ->schema([
                Select::make('type')
                    ->label('Tipo')
                    ->options([
                        StockMovementType::Entry->value => StockMovementType::Entry->getLabel(),
                        StockMovementType::Adjustment->value => StockMovementType::Adjustment->getLabel(),
                    ])
                    ->default(StockMovementType::Entry->value)
                    ->required()
                    ->live(),
                TextInput::make('quantity')
                    ->label('Cantidad')
                    ->helperText('En un ajuste usa un número negativo para descontar piezas.')
                    ->numeric()
                    ->integer()
                    ->required()
                    ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        if ((int) $value === 0) {
                            $fail('La cantidad no puede ser cero.');
                        } elseif ($get('type') === StockMovementType::Entry->value && (int) $value < 0) {
                            $fail('En una entrada la cantidad debe ser positiva.');
                        }
                    }),
                Textarea::make('notes')
                    ->label('Notas')
                    ->rows(3),
            ])
            ->action(function (array $data, array $arguments, Repeater $component, Action $action, $livewire): void {
                $variant = self::variantFor($component, $arguments);

                try {
                    app(StockService::class)->change(
                        $variant,
                        StockMovementType::from($data['type']),
                        (int) $data['quantity'],
                        auth()->user(),
                        $data['notes'] ?? null,
                    );
                } catch (InsufficientStockException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('No se pudo ajustar el stock')
                        ->body($exception->getMessage())
                        ->send();

                    $action->halt();
                }

                data_set($livewire->data, "variants.{$arguments['item']}.stock", $variant->stock);

                Notification::make()
                    ->success()
                    ->title('Stock actualizado')
                    ->send();
            });
    }

    private static function variantFor(Repeater $component, array $arguments): ?ProductVariant
    {
        $key = $arguments['item'] ?? null;

        return $key === null ? null : $component->getCachedExistingRecords()->get($key);
    }

    private static function fillSlug(Get $get, Set $set, string $operation): void
    {
        if ($operation === 'create') {
            $set('slug', Str::slug(trim($get('name').' '.$get('color'))));
        }
    }
}
