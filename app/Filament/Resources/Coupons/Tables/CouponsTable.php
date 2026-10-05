<?php

namespace App\Filament\Resources\Coupons\Tables;

use App\Enums\CouponType;
use App\Models\Coupon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Number;

class CouponsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Código')
                    ->weight('bold')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge(),
                TextColumn::make('value')
                    ->label('Valor')
                    ->formatStateUsing(fn ($state, Coupon $record) => $record->type === CouponType::Percent
                        ? rtrim(rtrim(number_format((float) $state, 2), '0'), '.').' %'
                        : Number::currency((float) $state))
                    ->sortable(),
                TextColumn::make('starts_at')
                    ->label('Empieza')
                    ->dateTime()
                    ->placeholder('Ya vigente')
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Termina')
                    ->dateTime()
                    ->placeholder('No vence')
                    ->sortable(),
                TextColumn::make('used_count')
                    ->label('Usos')
                    ->formatStateUsing(fn ($state, Coupon $record) => $record->max_uses === null ? "{$state} (sin límite)" : "{$state} de {$record->max_uses}")
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(CouponType::class),
                TernaryFilter::make('is_active')
                    ->label('Activo'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Sin cupones')
            ->emptyStateDescription('Crea un cupón para dar descuentos en esta tienda.');
    }
}
