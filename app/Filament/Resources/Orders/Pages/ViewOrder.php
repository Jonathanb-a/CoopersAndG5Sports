<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\DeliveryAttemptResult;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransitionException;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\CancellationRequestService;
use App\Services\OrderCancellationService;
use App\Services\OrderDeliveryService;
use App\Services\OrderReservationService;
use App\Services\OrderStatusService;
use App\Support\OrderWhatsAppMessage;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Detalle de un pedido con sus acciones. Las pueden usar el owner y el staff de la tienda.
 * Los cambios de estado pasan siempre por OrderStatusService.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public const CANCEL_REASONS = [
        'Cliente no pagó',
        'Cliente pidió cancelar',
        'Sin existencia real',
        'Producto dañado',
        'Error de captura',
        'Otro',
    ];

    protected function getHeaderActions(): array
    {
        return [
            $this->markPaidAction(),
            $this->markShippedAction(),
            $this->markDeliveredAction(),
            $this->scheduleDeliveryAction(),
            $this->recordAttemptAction(),
            $this->switchToPickupAction(),
            $this->extendAction(),
            $this->reactivateAction(),
            $this->markRefundedAction(),
            $this->rejectCancellationAction(),
            $this->whatsappAction(),
            $this->editNotesAction(),
            $this->editAddressAction(),
            $this->cancelAction(),
            $this->voidSaleAction(),
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
            ->modalDescription(fn (Order $record) => $record->paysInCash()
                ? 'El cliente paga en efectivo al entregar. Confirma el pedido para poder prepararlo y enviarlo; el dinero se cobra en la entrega.'
                : 'Confirma que ya recibiste la transferencia. Se guarda la fecha de pago.')
            ->modalSubmitActionLabel(fn (Order $record) => $record->paysInCash() ? 'Sí, confirmar pedido' : 'Sí, está pagado')
            // En efectivo no hay referencia que capturar.
            ->schema(fn (Order $record) => $record->paysInCash() ? [] : [
                TextInput::make('payment_reference')
                    ->label('Referencia de pago')
                    ->helperText('Folio o clave de rastreo de la transferencia.')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(fn (array $data) => $this->changeStatus(OrderStatus::Paid, ['payment_reference' => $data['payment_reference'] ?? null]));
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

    /** Entrega local: fija (o cambia) la fecha y el horario acordados con el cliente por WhatsApp. */
    private function scheduleDeliveryAction(): Action
    {
        $scheduled = fn (Order $record) => $record->delivery_scheduled_date !== null;

        return Action::make('scheduleDelivery')
            ->label(fn (Order $record) => $scheduled($record) ? 'Reprogramar entrega' : 'Programar entrega')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->color('info')
            ->visible(fn (Order $record) => OrderDeliveryService::canSchedule($record))
            ->modalHeading(fn (Order $record) => $scheduled($record) ? 'Reprogramar entrega a domicilio' : 'Programar entrega a domicilio')
            ->modalDescription('Captura la fecha y el horario que acordaste con el cliente por WhatsApp.')
            ->modalSubmitActionLabel('Guardar entrega')
            ->fillForm(fn (Order $record) => [
                'date' => $record->delivery_scheduled_date?->toDateString(),
                'window' => $record->delivery_window,
            ])
            ->schema([
                DatePicker::make('date')
                    ->label('Fecha de entrega')
                    ->required()
                    ->minDate(today())
                    ->rule('after_or_equal:today')
                    ->validationMessages(['after_or_equal' => 'La fecha de entrega no puede ser anterior a hoy.']),
                TextInput::make('window')
                    ->label('Horario')
                    ->placeholder('Entre 4 y 6 pm')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data, Order $record) {
                try {
                    $order = app(OrderDeliveryService::class)->schedule($record, $data['date'], $data['window'], auth()->user());
                } catch (InvalidOrderTransitionException $exception) {
                    Notification::make()->danger()->title('No se pudo programar la entrega')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Entrega programada para el '.$order->delivery_scheduled_date->format('d/m/Y'))->send();
            });
    }

    /** Entrega local: lo que pasó al llevar el pedido. «Entregado» cierra el pedido; lo demás lo deja por reprogramar. */
    private function recordAttemptAction(): Action
    {
        return Action::make('recordAttempt')
            ->label('Registrar intento')
            ->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->color('primary')
            ->visible(fn (Order $record) => OrderDeliveryService::canRecordAttempt($record))
            ->modalHeading('Registrar intento de entrega')
            ->modalDescription('Si se entregó, el pedido pasa a entregado. Si no, queda por reprogramar.')
            ->modalSubmitActionLabel('Registrar intento')
            ->schema([
                Select::make('result')
                    ->label('Resultado')
                    ->options(DeliveryAttemptResult::class)
                    ->required()
                    ->native(false)
                    ->live(),
                Textarea::make('notes')
                    ->label('Nota')
                    ->rows(2)
                    ->maxLength(500)
                    ->required(fn (Get $get) => self::isOther($get('result')))
                    ->validationMessages(['required' => 'Escribe una nota para explicar qué pasó en el intento.']),
                DatePicker::make('attempted_at')
                    ->label('Fecha del intento')
                    ->default(today()->toDateString())
                    ->required()
                    ->maxDate(today())
                    ->rule('before_or_equal:today')
                    ->validationMessages(['before_or_equal' => 'La fecha del intento no puede ser futura.']),
            ])
            ->action(function (array $data, Order $record) {
                $result = $data['result'] instanceof DeliveryAttemptResult ? $data['result'] : DeliveryAttemptResult::from($data['result']);

                try {
                    app(OrderDeliveryService::class)->recordAttempt($record, $result, $data['notes'] ?? null, auth()->user(), $data['attempted_at']);
                } catch (InvalidOrderTransitionException $exception) {
                    Notification::make()->danger()->title('No se registró el intento')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title($result->failed() ? 'Intento registrado: el pedido queda por reprogramar' : 'Pedido entregado')->send();
            });
    }

    private static function isOther(mixed $result): bool
    {
        return ($result instanceof DeliveryAttemptResult ? $result->value : $result) === DeliveryAttemptResult::Other->value;
    }

    /** Con 2 o más intentos fallidos: el cliente pasa por su pedido a la tienda. No cambia los totales. */
    private function switchToPickupAction(): Action
    {
        return Action::make('switchToPickup')
            ->label('Cambiar a recoger en tienda')
            ->icon(Heroicon::OutlinedBuildingStorefront)
            ->color('warning')
            ->visible(fn (Order $record) => OrderDeliveryService::canSwitchToPickup($record))
            ->requiresConfirmation()
            ->modalHeading('Cambiar a recoger en tienda')
            ->modalDescription('El pedido deja de ser entrega a domicilio y el cliente pasa por él a la tienda. Los totales no cambian; avísale por WhatsApp.')
            ->modalSubmitActionLabel('Sí, cambiar a recoger')
            ->action(function (Order $record) {
                try {
                    app(OrderDeliveryService::class)->switchToPickup($record, auth()->user());
                } catch (InvalidOrderTransitionException $exception) {
                    Notification::make()->danger()->title('No se pudo cambiar la entrega')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('El pedido ahora es para recoger en tienda')->send();
            });
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

    /** Cancelar: el staff puede cancelar un apartado; un pedido pagado solo lo cancela el owner. */
    private function cancelAction(): Action
    {
        $paid = fn (Order $record) => $record->status === OrderStatus::Paid;

        return Action::make('cancel')
            ->label('Cancelar pedido')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Order $record) => self::canCancel($record, auth()->user()))
            ->requiresConfirmation()
            ->modalHeading('Cancelar pedido')
            ->modalDescription(fn (Order $record) => $paid($record)
                ? 'Este pedido ya está pagado. Al cancelarlo hay que devolverle su dinero al cliente por transferencia.'
                : 'El pedido quedará cancelado. Esto no se puede deshacer.')
            ->modalSubmitActionLabel('Sí, cancelar pedido')
            ->schema(fn (Order $record) => [
                Select::make('reason')
                    ->label('Motivo')
                    ->options(array_combine(self::CANCEL_REASONS, self::CANCEL_REASONS))
                    ->required()
                    ->native(false),
                Textarea::make('note')
                    ->label('Nota')
                    ->rows(2)
                    ->maxLength(500),
                Checkbox::make('restock')
                    ->label('Regresar piezas al inventario')
                    ->helperText('Desmárcala si las piezas no vuelven a la venta (por ejemplo, producto dañado). Las piezas sobre pedido nunca se descontaron.')
                    ->default(true),
                Checkbox::make('refunded')
                    ->label('Reembolso realizado')
                    ->helperText('Márcala si ya le devolviste el dinero al cliente. Si no, el pedido queda con «Reembolso pendiente».')
                    ->visible($paid($record)),
            ])
            ->action(function (array $data, Order $record) {
                $reason = $data['reason'].(filled($data['note'] ?? null) ? ': '.trim($data['note']) : '');

                try {
                    app(OrderCancellationService::class)->cancel(
                        $record,
                        auth()->user(),
                        $reason,
                        restock: (bool) ($data['restock'] ?? false),
                        refunded: (bool) ($data['refunded'] ?? false),
                    );
                } catch (InvalidOrderTransitionException $exception) {
                    Notification::make()->danger()->title('No se pudo cancelar')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Pedido cancelado')->send();
            });
    }

    /** Anular una venta de mostrador: la única forma de cancelar un pedido entregado. Solo el owner. */
    private function voidSaleAction(): Action
    {
        return Action::make('voidSale')
            ->label('Anular venta')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->visible(fn (Order $record) => OrderStatusService::canVoid($record) && auth()->user()?->role === UserRole::Owner)
            ->requiresConfirmation()
            ->modalHeading('Anular venta de mostrador')
            ->modalDescription('La venta queda cancelada y sus piezas regresan al inventario. Devuélvele su dinero al cliente. Esto no se puede deshacer.')
            ->modalSubmitActionLabel('Sí, anular venta')
            ->schema([
                Textarea::make('reason')
                    ->label('Motivo')
                    ->required()
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data, Order $record) {
                try {
                    app(OrderCancellationService::class)->voidCounterSale($record, auth()->user(), $data['reason']);
                } catch (InvalidOrderTransitionException $exception) {
                    Notification::make()->danger()->title('No se pudo anular la venta')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Venta anulada')->send();
            });
    }

    public static function canCancel(Order $order, ?User $user): bool
    {
        return match ($order->status) {
            OrderStatus::Reserved => $user !== null,
            OrderStatus::Paid => $user?->role === UserRole::Owner,
            default => false,
        };
    }

    private function extendAction(): Action
    {
        return Action::make('extend')
            ->label('Dar más tiempo')
            ->icon(Heroicon::OutlinedClock)
            ->color('gray')
            ->visible(fn (Order $record) => OrderReservationService::canExtend($record))
            ->modalHeading(fn (Order $record) => $record->paysInCash() ? 'Dar más tiempo al pedido' : 'Dar más tiempo para pagar')
            ->modalDescription(fn (Order $record) => $record->paysInCash()
                ? 'Se suma a la fecha límite del pedido, sin pasar de '.(int) config('shop.cash_reserve_max_days', 7).' días desde que se creó.'
                : 'Se suma a la fecha límite de pago del pedido.')
            ->modalSubmitActionLabel('Dar más tiempo')
            ->schema([
                Radio::make('hours')
                    ->label('Tiempo extra')
                    ->options([24 => '24 horas', 48 => '48 horas'])
                    ->default(24)
                    ->required(),
            ])
            ->action(function (array $data, Order $record) {
                try {
                    $order = app(OrderReservationService::class)->extend($record, (int) $data['hours'], auth()->user());
                } catch (InvalidOrderTransitionException $exception) {
                    Notification::make()->danger()->title('No se pudo dar más tiempo')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Nueva fecha límite: '.$order->expires_at->format('d/m/Y H:i'))->send();
            });
    }

    /** Solo para pedidos que canceló el sistema (venció el apartado) y que nunca se pagaron. */
    private function reactivateAction(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivar pedido')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (Order $record) => OrderStatusService::canReactivate($record))
            ->requiresConfirmation()
            ->modalHeading('Reactivar pedido')
            ->modalDescription('Se vuelven a apartar las piezas y el pedido recibe una fecha límite de pago nueva. Si de alguna talla ya no hay piezas, no se reactiva.')
            ->modalSubmitActionLabel('Sí, reactivar')
            ->action(function (Order $record) {
                try {
                    $order = app(OrderReservationService::class)->reactivate($record, auth()->user());
                } catch (InvalidOrderTransitionException $exception) {
                    Notification::make()->danger()->title('No se reactivó el pedido')->body($exception->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Pedido reactivado')->body('Fecha límite de pago: '.$order->expires_at->format('d/m/Y H:i'))->send();
            });
    }

    /**
     * El cliente pidió cancelar un pedido pagado. El personal decide: lo cancela con «Cancelar pedido»
     * o rechaza la solicitud aquí, que la limpia y deja la nota en el historial.
     */
    private function rejectCancellationAction(): Action
    {
        return Action::make('rejectCancellation')
            ->label('Rechazar solicitud')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('gray')
            ->visible(fn (Order $record) => $record->cancellation_requested_at !== null && $record->status !== OrderStatus::Cancelled)
            ->requiresConfirmation()
            ->modalHeading('Rechazar solicitud de cancelación')
            ->modalDescription('El pedido sigue su curso. La solicitud se quita y queda la nota en el historial; avísale al cliente por WhatsApp.')
            ->modalSubmitActionLabel('Rechazar solicitud')
            ->schema([
                Textarea::make('note')
                    ->label('Nota')
                    ->helperText('Por qué no se cancela. Solo la ve el personal.')
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data, Order $record) {
                try {
                    app(CancellationRequestService::class)->reject($record, auth()->user(), $data['note'] ?? null);
                } catch (InvalidOrderTransitionException $exception) {
                    Notification::make()->danger()->title('No se pudo rechazar')->body($exception->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Solicitud rechazada')->send();
            });
    }

    /** Cierra un «Reembolso pendiente» cuando el dinero ya se devolvió. Como cancelar un pagado, es del owner. */
    private function markRefundedAction(): Action
    {
        return Action::make('markRefunded')
            ->label('Registrar reembolso')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('warning')
            ->visible(fn (Order $record) => $record->refundPending() && auth()->user()?->role === UserRole::Owner)
            ->requiresConfirmation()
            ->modalHeading('Registrar reembolso')
            ->modalDescription('Confirma que ya le devolviste el dinero al cliente por transferencia.')
            ->modalSubmitActionLabel('Sí, ya se reembolsó')
            ->action(function (Order $record) {
                if ($record->refundPending()) {
                    $record->update(['refunded_at' => now()]);
                    app(OrderStatusService::class)->annotate($record, auth()->user(), 'Reembolso realizado.');
                }

                Notification::make()->success()->title('Reembolso registrado')->send();
            });
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
                // En la entrega local el cliente solo da un punto de entrega: lo demás es opcional.
                TextInput::make('street')->label('Punto de entrega o dirección')->required()->maxLength(500),
                TextInput::make('neighborhood')->label('Colonia')->maxLength(255),
                TextInput::make('city')->label('Ciudad')->maxLength(255),
                TextInput::make('state')->label('Estado')->maxLength(255),
                TextInput::make('zip')
                    ->label('Código postal')
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
