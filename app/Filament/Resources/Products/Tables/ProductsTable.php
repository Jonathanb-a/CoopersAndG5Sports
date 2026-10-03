<?php

namespace App\Filament\Resources\Products\Tables;

use App\Enums\StockLevel;
use App\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('images'))
            ->columns([
                ImageColumn::make('thumbnail')
                    ->label('Foto')
                    ->disk('public')
                    ->state(fn (Product $record): ?string => $record->images->first()?->path),
                TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('color')
                    ->label('Color'),
                TextColumn::make('category.name')
                    ->label('Categoría'),
                TextColumn::make('brand.name')
                    ->label('Marca'),
                TextColumn::make('price')
                    ->label('Precio')
                    ->money('MXN')
                    ->sortable(),
                TextColumn::make('variants_sum_stock')
                    ->label('Stock total')
                    ->sum('variants', 'stock')
                    ->default(0)
                    ->badge()
                    ->color(fn ($state): string => StockLevel::forProduct((int) $state)->getColor())
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('category')
                    ->label('Categoría')
                    ->relationship('category', 'name'),
                SelectFilter::make('brand')
                    ->label('Marca')
                    ->relationship('brand', 'name'),
                Filter::make('sold_out')
                    ->label('Agotados')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->soldOut()),
                Filter::make('last_piece')
                    ->label('Última pieza')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->lastPiece()),
                TernaryFilter::make('is_active')
                    ->label('Activo'),
                TrashedFilter::make()
                    ->label('Papelera'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
