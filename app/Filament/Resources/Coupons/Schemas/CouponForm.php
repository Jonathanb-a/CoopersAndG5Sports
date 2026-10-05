<?php

namespace App\Filament\Resources\Coupons\Schemas;

use App\Enums\CouponType;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class CouponForm
{
    /** El tipo llega como valor ("percent") o como enum, según el momento del formulario. */
    private static function isPercent(mixed $type): bool
    {
        return $type === CouponType::Percent || $type === CouponType::Percent->value;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Código')
                    ->helperText('Lo que escribe el cliente. Se guarda en mayúsculas y no se repite en la tienda.')
                    ->required()
                    ->maxLength(50)
                    ->regex('/^[A-Za-z0-9_-]+$/')
                    ->extraInputAttributes(['class' => 'uppercase'])
                    // Se valida el código ya normalizado: "verano" y "VERANO" son el mismo.
                    ->mutateStateForValidationUsing(fn (?string $state) => Str::upper(trim((string) $state)))
                    ->dehydrateStateUsing(fn (?string $state) => Str::upper(trim((string) $state)))
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule) => $rule->where('store_id', Filament::getTenant()?->getKey()),
                    )
                    ->validationMessages([
                        'regex' => 'Usa solo letras, números, guion (-) y guion bajo (_), sin espacios.',
                        'unique' => 'Ya hay un cupón con ese código en esta tienda.',
                    ]),
                Select::make('type')
                    ->label('Tipo de descuento')
                    ->options(CouponType::class)
                    ->default(CouponType::Percent)
                    ->required()
                    ->native(false)
                    ->live(),
                TextInput::make('value')
                    ->label('Valor')
                    ->helperText(fn (Get $get) => self::isPercent($get('type')) ? 'Porcentaje de 0.01 a 100.' : 'Monto en pesos que se descuenta.')
                    ->required()
                    ->numeric()
                    ->minValue(0.01)
                    ->maxValue(fn (Get $get) => self::isPercent($get('type')) ? 100 : 99999999.99)
                    ->prefix(fn (Get $get) => self::isPercent($get('type')) ? null : '$')
                    ->suffix(fn (Get $get) => self::isPercent($get('type')) ? '%' : 'MXN'),
                DateTimePicker::make('starts_at')
                    ->label('Empieza')
                    ->helperText('Opcional. Vacío = ya está vigente.')
                    ->seconds(false),
                DateTimePicker::make('ends_at')
                    ->label('Termina')
                    ->helperText('Opcional. Vacío = no vence.')
                    ->seconds(false)
                    ->afterOrEqual('starts_at'),
                TextInput::make('max_uses')
                    ->label('Usos máximos')
                    ->helperText('Opcional. Vacío = sin límite.')
                    ->numeric()
                    ->integer()
                    ->minValue(1),
                TextInput::make('used_count')
                    ->label('Veces usado')
                    ->helperText('Se actualiza solo cuando se crea un pedido con este cupón.')
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),
                Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true),
            ]);
    }
}
