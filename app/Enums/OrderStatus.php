<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasLabel
{
    case Reserved = 'apartado';
    case Paid = 'pagado';
    case Shipped = 'enviado';
    case Delivered = 'entregado';
    case Cancelled = 'cancelado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Reserved => 'Apartado',
            self::Paid => 'Pagado',
            self::Shipped => 'Enviado',
            self::Delivered => 'Entregado',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * Etiqueta para mostrar según la entrega: "Enviado" se dice "Listo para recoger" cuando el
     * pedido se recoge en tienda y "En camino" en los demás métodos. El resto no cambia.
     */
    public function labelFor(?DeliveryMethod $method): string
    {
        if ($this !== self::Shipped || $method === null) {
            return $this->getLabel();
        }

        return $method === DeliveryMethod::Pickup ? 'Listo para recoger' : 'En camino';
    }

    /**
     * Estados a los que se puede pasar desde este. Entregado y cancelado son finales.
     *
     * @return list<self>
     */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::Reserved => [self::Paid, self::Cancelled],
            self::Paid => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->nextStatuses(), true);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Reserved => 'warning',
            self::Paid => 'success',
            self::Shipped => 'info',
            self::Delivered => 'primary',
            self::Cancelled => 'danger',
        };
    }

    /** Un pedido cuenta como venta cuando ya se pagó y no se canceló. */
    public function countsAsSale(): bool
    {
        return in_array($this, [self::Paid, self::Shipped, self::Delivered], true);
    }

    /** @return list<string> valores de los estados que cuentan como venta */
    public static function saleValues(): array
    {
        return array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->countsAsSale()),
        );
    }
}
