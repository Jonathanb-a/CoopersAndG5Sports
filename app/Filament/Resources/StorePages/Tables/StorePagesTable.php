<?php

namespace App\Filament\Resources\StorePages\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StorePagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Título')->searchable(),
                TextColumn::make('slug')->label('Dirección')->formatStateUsing(fn (string $state): string => "/pagina/{$state}"),
                TextColumn::make('internal_note')->label('Nota interna')->placeholder('—')->limit(40),
                IconColumn::make('is_published')->label('Publicada')->boolean(),
                TextColumn::make('updated_at')->label('Actualizada')->since(),
            ])
            ->defaultSort('title')
            ->recordActions([EditAction::make()])
            ->emptyStateHeading('Sin páginas')
            ->emptyStateDescription('Crea las páginas de contenido y legales de la tienda.');
    }
}
