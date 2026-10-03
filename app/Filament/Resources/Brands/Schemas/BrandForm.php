<?php

namespace App\Filament\Resources\Brands\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class BrandForm
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
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['unique' => 'Ya existe una marca con este nombre.']),
            ]);
    }
}
