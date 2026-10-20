<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\Actions\SendPasswordLinkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('Correo electrónico')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('role')
                    ->label('Rol')
                    ->badge(),
                TextColumn::make('stores.name')
                    ->label('Tiendas')
                    ->badge(),
            ])
            ->recordActions([
                EditAction::make(),
                SendPasswordLinkAction::make(),
            ]);
    }
}
