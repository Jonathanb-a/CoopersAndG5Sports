<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\OrderStatusService;
use App\Support\OrderWhatsAppMessage;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Detalle de un pedido con sus acciones. Las pueden usar el owner y el staff de la tienda.
 * Los cambios de estado pasan siempre por OrderStatusService.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->markPaidAction(),
            $this->markShippedAction(),
            $this->markDeliveredAction(),
            $this->whatsappAction(),
            $this->editNotesAction(),
            $this->editAddressAction(),
        ];
    }

    private function markPaidAction(): Action
    {
        return Action::make('markPaid')
            ->label('Marcar pagado')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Order $record) => $record->status->canTransitionTo(OrderStatus::Paid))
            ->requiresConfirmation()
            ->modalHeading('Marcar pedido como pagado')
            ->modalDescription('Confirma que ya recibiste la transferencia. Se guarda la fecha de pago.')
            ->modalSubmitActionLabel('Sí, está pagado')
            ->schema([
                TextInput::make('payment_reference')
                    ->label('Referencia de pago')
                    ->helperText('Folio o clave de rastreo de la transferencia.')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(fn (array $data) => $this->changeStatus(OrderStatus::Paid, ['payment_reference' => $data['payment_reference']]));
    }

    private function markShippedAction(): Action
    {
        $pickup = fn (Order $record) => $record->delivery_method === DeliveryMethod::Pickup;
        $national = fn (Order $record) => $record->delivery_method === DeliveryMethod::National;

        return Action::make('markShipped')
            ->label(fn (Order $record) => $pickup($record) ? 'Marcar listo para recoger' : 'Marcar enviado')
            ->icon(Heroicon::OutlinedTruck)
            ->color('info')
            ->visible(fn (Order $record) => $record->status->canTransitionTo(OrderStatus::Shipped))
            ->requiresConfirmation()
            ->modalHeading(fn (Order $record) => $pickup($record) ? 'Marcar listo para recoger' : 'Marcar pedido como enviado')
            ->modalDescription(fn (Order $record) => match (true) {
                $pickup($record) => 'Confirma que el pedido ya está listo en la tienda.',
                $national($record) => 'Captura la paquetería y el número de guía del envío.',
                default => 'Confirma que el pedido ya va en camino.',
            })
            ->modalSubmitActionLabel('Confirmar')
            // Solo el envío nacional pide (y exige) paquetería y guía.
            ->schema(fn (Order $record) => $national($record) ? [
                TextInput::make('tracking_carrier')
                    ->label('Paquetería')
                    ->default($record->tracking_carrier)
                    ->required()
                    ->maxLength(255),
                TextInput::make('tracking_number')
                    ->label('Número de guía')
                    ->default($record->tracking_number)
                    ->required()
                    ->maxLength(255),
            ] : [])
            ->action(fn (array $data) => $this->changeStatus(OrderStatus::Shipped, $data));
    }

    private function markDeliveredAction(): Action
    {
        return Action::make('markDelivered')
            ->label('Marcar entregado')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('primary')
            ->visible(fn (Order $record) => $record->status->canTransitionTo(OrderStatus::Delivered))
            ->requiresConfirmation()
            ->modalHeading('Marcar pedido como entregado')
            ->modalDescription('Confirma que el cliente ya recibió su pedido. Se guarda la fecha de entrega.')
            ->modalSubmitActionLabel('Sí, se entregó')
            ->action(fn () => $this->changeStatus(OrderStatus::Delivered));
    }

    /** Abre WhatsApp con el aviso del estado actual. No manda nada solo: el personal revisa y envía. */
    private function whatsappAction(): Action
    {
        return Action::make('whatsapp')
            ->label('Avisar por WhatsApp')
            ->icon(Heroicon::OutlinedChatBubbleLeftRight)
            ->color('gray')
            ->visible(fn (Order $record) => OrderWhatsAppMessage::statusNoticeUrl($record) !== null)
            ->url(fn (Order $record) => OrderWhatsAppMessage::statusNoticeUrl($record))
            ->openUrlInNewTab();
    }

    private function editNotesAction(): Action
    {
        return Action::make('editNotes')
            ->label('Notas internas')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('gray')
            ->modalHeading('Notas internas')
            ->modalDescription('Solo las ve el personal de la tienda. El cliente no las ve.')
            ->modalSubmitActionLabel('Guardar notas')
            ->fillForm(fn (Order $record) => ['internal_notes' => $record->internal_notes])
            ->schema([
                Textarea::make('internal_notes')
                    ->label('Notas internas')
                    ->rows(5)
                    ->maxLength(2000),
            ])
            ->action(function (array $data, Order $record) {
                $record->update(['internal_notes' => filled($data['internal_notes'] ?? null) ? trim($data['internal_notes']) : null]);

                Notification::make()->success()->title('Notas guardadas')->send();
            });
    }

    /** La dirección se puede corregir solo mientras el pedido no haya salido. */
    private function editAddressAction(): Action
    {
        return Action::make('editAddress')
            ->label('Editar dirección')
            ->icon(Heroicon::OutlinedMapPin)
            ->color('gray')
            ->visible(fn (Order $record) => self::canEditAddress($record))
            ->modalHeading('Editar dirección de entrega')
            ->modalSubmitActionLabel('Guardar dirección')
            ->fillForm(fn (Order $record) => $record->shipping_address ?? [])
            ->schema([
                TextInput::make('name')->label('Recibe')->maxLength(255),
                TextInput::make('phone')->label('Teléfono')->tel()->maxLength(20),
                TextInput::make('street')->label('Calle y número')->required()->maxLength(255),
                TextInput::make('neighborhood')->label('Colonia')->required()->maxLength(255),
                TextInput::make('city')->label('Ciudad')->required()->maxLength(255),
                TextInput::make('state')->label('Estado')->required()->maxLength(255),
                TextInput::make('zip')
                    ->label('Código postal')
                    ->required()
                    ->regex('/^\d{5}$/')
                    ->validationMessages(['regex' => 'El código postal debe tener 5 dígitos.']),
                Textarea::make('notes')->label('Referencias')->rows(2)->maxLength(1000),
            ])
            ->action(function (array $data, Order $record) {
                // Se revisa otra vez al guardar: el pedido pudo salir mientras el formulario estaba abierto.
                if (! self::canEditAddress($record->refresh())) {
                    Notification::make()->danger()->title('El pedido ya salió: la dirección no se puede cambiar.')->send();

                    return;
                }

                $record->update(['shipping_address' => [...($record->shipping_address ?? []), ...$data]]);

                Notification::make()->success()->title('Dirección guardada')->send();
            });
    }

    public static function canEditAddress(Order $order): bool
    {
        return $order->delivery_method !== DeliveryMethod::Pickup
            && in_array($order->status, [OrderStatus::Reserved, OrderStatus::Paid], true);
    }

    private function changeStatus(OrderStatus $to, array $data = []): void
    {
        try {
            app(OrderStatusService::class)->transition($this->getRecord(), $to, auth()->user(), data: $data);
        } catch (InvalidOrderTransitionException $exception) {
            Notification::make()->danger()->title('No se pudo cambiar el estado')->body($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title('Pedido actualizado: '.$this->getRecord()->statusLabel())->send();
    }
}
