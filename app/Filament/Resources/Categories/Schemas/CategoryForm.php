<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Enums\SizeType;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, string $operation) {
                        if ($operation === 'create') {
                            $set('slug', Str::slug((string) $get('name')));
                        }
                    }),
                TextInput::make('slug')
                    ->label('Slug')
                    ->helperText('Se genera automáticamente a partir del nombre.')
                    ->readOnly()
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule) => $rule->where('store_id', Filament::getTenant()?->getKey()),
                    )
                    ->validationMessages(['unique' => 'Ya existe una categoría con este nombre en la tienda.']),
                Select::make('size_type')
                    ->label('Tipo de talla')
                    ->options(SizeType::class)
                    ->required(),
            ]);
    }
}
