<?php

namespace App\Filament\Widgets;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/** Entregas a domicilio (pedidos locales) de la tienda: las de hoy, las que faltan por programar y por reprogramar. */
class HomeDeliveries extends TableWidget
{
    public const TABS = [
        'today' => 'Hoy',
        'unscheduled' => 'Por programar',
        'reschedule' => 'Por reprogramar',
    ];

    protected static ?int $sort = 7;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.home-deliveries';

    public string $activeTab = 'today';

    /** @return array<string, string> */
    public function getTabs(): array
    {
        return self::TABS;
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
    }

    protected function getTableHeading(): string|Htmlable|null
    {
        return 'Entregas a domicilio';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->tabQuery())
            ->columns([
                TextColumn::make('number')
                    ->label('Número'),
                TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->placeholder('—'),
                TextColumn::make('contact_phone')
                    ->label('Teléfono')
                    ->state(fn (Order $record): ?string => $record->contactPhone())
                    ->placeholder('—'),
                TextColumn::make('address')
                    ->label('Dirección')
                    ->state(fn (Order $record): ?string => self::address($record))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('delivery_window')
                    ->label('Horario')
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->activeTab === 'today'),
            ])
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->emptyStateHeading(fn (): string => match ($this->activeTab) {
                'unscheduled' => 'Sin entregas por programar',
                'reschedule' => 'Sin entregas por reprogramar',
                default => 'Sin entregas para hoy',
            })
            ->emptyStateDescription(fn (): string => match ($this->activeTab) {
                'unscheduled' => 'Todos los pedidos locales pagados ya tienen fecha de entrega.',
                'reschedule' => 'Ningún pedido tiene un intento de entrega fallido pendiente.',
                default => 'No hay entregas a domicilio programadas para hoy.',
            });
    }

    private function tabQuery(): Builder
    {
        $query = Order::query()
            ->where('orders.store_id', Filament::getTenant()?->getKey())
            ->where('orders.delivery_method', DeliveryMethod::Local->value)
            ->with('customer')
            ->orderBy('orders.id');

        return match ($this->activeTab) {
            // Locales ya pagados que todavía no tienen fecha.
            'unscheduled' => $query
                ->where('orders.status', OrderStatus::Paid->value)
                ->whereNull('orders.delivery_scheduled_date'),
            // Enviados cuyo último intento falló y siguen sin fecha nueva.
            'reschedule' => $query->needsDeliveryReschedule(),
            default => $query
                ->whereIn('orders.status', [OrderStatus::Paid->value, OrderStatus::Shipped->value])
                ->whereDate('orders.delivery_scheduled_date', today()),
        };
    }

    private static function address(Order $order): ?string
    {
        $address = $order->shipping_address ?? [];

        return implode(', ', array_filter([$address['street'] ?? null, $address['neighborhood'] ?? null])) ?: null;
    }
}
