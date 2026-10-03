<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('customer'))
            ->columns([
                TextColumn::make('number')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->placeholder('—'),
                TextColumn::make('channel')
                    ->label('Canal')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
                TextColumn::make('total')
                    ->label('Total')
                    ->money()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(OrderStatus::class),
                SelectFilter::make('channel')
                    ->label('Canal')
                    ->options(OrderChannel::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
