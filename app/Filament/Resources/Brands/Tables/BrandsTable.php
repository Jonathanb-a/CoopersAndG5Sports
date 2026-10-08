<?php

namespace App\Filament\Resources\Brands\Tables;

use App\Filament\Resources\Brands\BrandResource;
use App\Models\Brand;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->visible(fn (Brand $record) => BrandResource::canEdit($record)),
                DeleteAction::make()
                    ->visible(fn (Brand $record) => BrandResource::canDelete($record))
                    ->before(BrandResource::blockDeleteWhenInUse()),
            ]);
    }
}
