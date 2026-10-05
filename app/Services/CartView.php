<?php

namespace App\Services;

/**
 * El carrito tal como está hoy en la base de datos: líneas con precio, nombre, foto y estado reales.
 * Los avisos (se agotó, cambió el precio...) solo vienen llenos cuando se llama a CartService::reconcile().
 */
final readonly class CartView
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  list<string>  $notices
     */
    public function __construct(
        public array $lines,
        public int $subtotalCents,
        public array $notices = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** Número de piezas (suma de cantidades). */
    public function count(): int
    {
        return (int) array_sum(array_column($this->lines, 'quantity'));
    }

    /** Subtotal en pesos ("1700.00"). */
    public function subtotal(): string
    {
        return CouponResult::money($this->subtotalCents);
    }
}
