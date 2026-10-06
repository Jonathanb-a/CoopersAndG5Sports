<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\DeliveryAttemptResult;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Services\OrderDeliveryService;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
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
                        TextEntry::make('number')->label('Número')->weight('bold'),
                        TextEntry::make('status')
                            ->label('Estado')
                            ->badge()
                            ->formatStateUsing(fn (OrderStatus $state, Order $record) => $record->statusLabel()),
                        TextEntry::make('channel')->label('Canal')->badge(),
                        TextEntry::make('delivery_method')->label('Entrega')->badge()->color('gray'),
                        TextEntry::make('created_at')->label('Creado')->dateTime(),
                        TextEntry::make('expires_at')
                            ->label('Fecha límite de pago')
                            ->dateTime()
                            ->placeholder('—')
                            ->color(fn (Order $record) => $record->status === OrderStatus::Reserved && $record->expires_at?->isPast() ? 'danger' : null),
                        TextEntry::make('paid_at')->label('Pagado')->dateTime()->placeholder('—'),
                        TextEntry::make('payment_method')->label('Método de pago')->placeholder('—'),
                        TextEntry::make('payment_reference')->label('Referencia de pago')->placeholder('—'),
                        TextEntry::make('delivered_at')->label('Entregado')->dateTime()->placeholder('—'),
                        TextEntry::make('tracking_carrier')->label('Paquetería')->placeholder('—'),
                        TextEntry::make('tracking_number')->label('Número de guía')->placeholder('—')->copyable(),
                        TextEntry::make('cancellation_requested_at')
                            ->label('Solicitó cancelar')
                            ->dateTime()
                            ->color('danger')
                            ->visible(fn (Order $record) => $record->cancellation_requested_at !== null),
                        TextEntry::make('cancellation_request_note')
                            ->label('Motivo de la solicitud')
                            ->columnSpanFull()
                            ->visible(fn (Order $record) => filled($record->cancellation_request_note)),
                    ]),
                Section::make('Cancelación')
                    ->columnSpanFull()
                    ->columns(4)
                    ->visible(fn (Order $record) => $record->status === OrderStatus::Cancelled)
                    ->schema([
                        TextEntry::make('cancelled_at')->label('Cancelado')->dateTime()->placeholder('—'),
                        TextEntry::make('cancelled_by')->label('Canceló')->placeholder('—'),
                        TextEntry::make('cancel_reason')->label('Motivo')->placeholder('—'),
                        TextEntry::make('refunded_at')
                            ->label('Reembolso')
                            ->dateTime()
                            ->placeholder(fn (Order $record) => $record->refundPending() ? 'Reembolso pendiente' : 'No aplica')
                            ->color(fn (Order $record) => $record->refundPending() ? 'warning' : null),
                    ]),
                Section::make('Cliente')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('customer.name')->label('Nombre')->placeholder('Mostrador'),
                        TextEntry::make('customer.email')->label('Correo')->placeholder('—')->copyable(),
                        TextEntry::make('contact_phone')
                            ->label('Teléfono')
                            ->state(fn (Order $record) => $record->contactPhone())
                            ->placeholder('—')
                            ->copyable(),
                    ]),
                Section::make('Dirección de entrega')
                    ->columns(3)
                    ->visible(fn (Order $record) => filled($record->shipping_address['street'] ?? null))
                    ->schema([
                        TextEntry::make('shipping_address.name')->label('Recibe')->placeholder('—'),
                        TextEntry::make('shipping_address.street')->label('Calle y número'),
                        TextEntry::make('shipping_address.neighborhood')->label('Colonia'),
                        TextEntry::make('shipping_address.city')->label('Ciudad'),
                        TextEntry::make('shipping_address.state')->label('Estado'),
                        TextEntry::make('shipping_address.zip')->label('Código postal'),
                        TextEntry::make('shipping_address.notes')->label('Referencias')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Entrega a domicilio')
                    ->columnSpanFull()
                    ->columns(2)
                    // También después de cambiar a recoger: los intentos que hubo se siguen viendo.
                    ->visible(fn (Order $record) => $record->delivery_method === DeliveryMethod::Local || $record->deliveryAttempts->isNotEmpty())
                    ->schema([
                        TextEntry::make('delivery_scheduled_date')
                            ->label('Entrega programada')
                            ->date('d/m/Y')
                            ->placeholder(fn (Order $record) => $record->needsDeliveryReschedule() ? 'Por reprogramar' : 'Sin programar')
                            ->color(fn (Order $record) => $record->needsDeliveryReschedule() ? 'warning' : null),
                        TextEntry::make('delivery_window')->label('Horario')->placeholder('—'),
                        TextEntry::make('delivery_warning')
                            ->label('Advertencia')
                            ->columnSpanFull()
                            ->color('danger')
                            ->weight('bold')
                            ->state(fn (Order $record) => "Van {$record->failedDeliveryAttempts()} intentos de entrega fallidos. Puedes cambiar el pedido a recoger en tienda.")
                            ->visible(fn (Order $record) => OrderDeliveryService::canSwitchToPickup($record)),
                        RepeatableEntry::make('deliveryAttempts')
                            ->label('Intentos de entrega')
                            ->columnSpanFull()
                            ->columns(4)
                            ->placeholder('Sin intentos registrados.')
                            ->schema([
                                TextEntry::make('attempted_at')->label('Fecha')->date('d/m/Y'),
                                TextEntry::make('result')
                                    ->label('Resultado')
                                    ->badge()
                                    ->color(fn (DeliveryAttemptResult $state) => $state->failed() ? 'danger' : 'success'),
                                TextEntry::make('notes')->label('Nota')->placeholder('—'),
                                TextEntry::make('user.name')->label('Registró')->placeholder('—'),
                            ]),
                    ]),
                Section::make('Productos')
                    ->columnSpanFull()
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->columns(6)
                            ->schema([
                                ImageEntry::make('photo')
                                    ->label('Foto')
                                    ->state(fn (OrderItem $record) => $record->productVariant?->product?->images->first()?->path)
                                    ->disk('public')
                                    ->imageHeight(64)
                                    ->placeholder('Sin foto'),
                                TextEntry::make('product_name')->label('Producto'),
                                TextEntry::make('size')->label('Talla'),
                                TextEntry::make('quantity')->label('Cantidad'),
                                TextEntry::make('unit_price')->label('Precio unitario')->money(),
                                IconEntry::make('is_backorder')->label('Sobre pedido')->boolean(),
                            ]),
                    ]),
                Section::make('Totales')
                    ->columnSpanFull()
                    ->columns(5)
                    ->schema([
                        TextEntry::make('subtotal')->label('Subtotal')->money(),
                        TextEntry::make('coupon.code')->label('Cupón')->placeholder('Sin cupón'),
                        TextEntry::make('discount')->label('Descuento')->money(),
                        TextEntry::make('shipping_cost')->label('Envío')->money(),
                        TextEntry::make('total')->label('Total')->money()->weight('bold'),
                    ]),
                Section::make('Notas')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('notes')->label('Notas del cliente')->placeholder('—'),
                        TextEntry::make('internal_notes')
                            ->label('Notas internas')
                            ->helperText('Solo las ve el personal de la tienda.')
                            ->placeholder('—'),
                    ]),
                Section::make('Historial')
                    ->columnSpanFull()
                    ->schema([
                        // Línea de tiempo: del más viejo al más nuevo.
                        RepeatableEntry::make('statusHistories')
                            ->hiddenLabel()
                            ->columns(4)
                            ->placeholder('Sin movimientos registrados.')
                            ->schema([
                                TextEntry::make('created_at')->label('Fecha')->dateTime(),
                                TextEntry::make('to_status')
                                    ->label('Cambio')
                                    ->state(fn (OrderStatusHistory $record) => self::change($record)),
                                TextEntry::make('actor')
                                    ->label('Quién')
                                    ->state(fn (OrderStatusHistory $record) => $record->actorLabel()),
                                TextEntry::make('note')->label('Nota')->placeholder('—'),
                            ]),
                    ]),
            ]);
    }

    /** "Apartado → Pagado", con "Enviado" dicho según la entrega del pedido. */
    public static function change(OrderStatusHistory $history): string
    {
        $method = $history->order?->delivery_method;
        $to = $history->to_status->labelFor($method);

        // Mismo estado antes y después: es una anotación (más tiempo, reembolso...), no un cambio.
        if ($history->from_status === $history->to_status) {
            return "Anotación ({$to})";
        }

        return $history->from_status === null
            ? "Pedido creado: {$to}"
            : $history->from_status->labelFor($method).' → '.$to;
    }
}
