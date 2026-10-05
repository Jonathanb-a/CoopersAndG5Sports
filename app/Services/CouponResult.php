<?php

namespace App\Services;

use App\Models\Coupon;

/** Resultado de revisar un cupón: si vale, cuánto descuenta y el total, o el motivo por el que no. */
final readonly class CouponResult
{
    private function __construct(
        public bool $valid,
        public ?Coupon $coupon,
        public ?string $reason,
        public int $subtotalCents,
        public int $discountCents,
    ) {}

    public static function valid(Coupon $coupon, int $subtotalCents, int $discountCents): self
    {
        return new self(true, $coupon, null, $subtotalCents, $discountCents);
    }

    public static function invalid(string $reason, int $subtotalCents, ?Coupon $coupon = null): self
    {
        return new self(false, $coupon, $reason, $subtotalCents, 0);
    }

    public function subtotal(): string
    {
        return self::money($this->subtotalCents);
    }

    /** Descuento en pesos; "0.00" si el cupón no vale. */
    public function discount(): string
    {
        return self::money($this->discountCents);
    }

    /** Subtotal menos descuento; nunca negativo. */
    public function total(): string
    {
        return self::money(max(0, $this->subtotalCents - $this->discountCents));
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
