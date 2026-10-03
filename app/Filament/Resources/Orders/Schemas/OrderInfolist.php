<?php

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pedido')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('number')->label('Número'),
                        TextEntry::make('status')->label('Estado')->badge(),
                        TextEntry::make('channel')->label('Canal')->badge(),
                        TextEntry::make('customer.name')->label('Cliente')->placeholder('—'),
                        TextEntry::make('delivery_method')->label('Entrega')->badge()->color('gray'),
                        TextEntry::make('created_at')->label('Creado')->dateTime(),
                        TextEntry::make('expires_at')->label('Vence')->dateTime()->placeholder('—'),
                        TextEntry::make('paid_at')->label('Pagado')->dateTime()->placeholder('—'),
                        TextEntry::make('delivered_at')->label('Entregado')->dateTime()->placeholder('—'),
                        TextEntry::make('notes')->label('Notas')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Productos')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->columns(4)
                            ->schema([
                                TextEntry::make('product_name')->label('Producto'),
                                TextEntry::make('size')->label('Talla'),
                                TextEntry::make('quantity')->label('Cantidad'),
                                TextEntry::make('unit_price')->label('Precio unitario')->money(),
                            ]),
                    ]),
                Section::make('Totales')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('subtotal')->label('Subtotal')->money(),
                        TextEntry::make('discount')->label('Descuento')->money(),
                        TextEntry::make('shipping_cost')->label('Envío')->money(),
                        TextEntry::make('total')->label('Total')->money()->weight('bold'),
                    ]),
            ]);
    }
}
