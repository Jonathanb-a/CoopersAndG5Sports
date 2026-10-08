<?php

namespace App\Services;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Models\Store;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Cupones de descuento: valida un código para una tienda, calcula el descuento sobre el subtotal
 * y guarda en la sesión el cupón aplicado (que se revalida cada vez que se consulta).
 *
 * No incrementa used_count: eso pasa al crear el pedido, dentro de su transacción.
 * Todo el dinero se calcula en centavos enteros para no arrastrar errores de decimales.
 */
class CouponService
{
    /** Llave de la sesión con el cupón aplicado: ['store_id' => int, 'code' => string]. */
    public const SESSION_KEY = 'shop.coupon';

    /**
     * Revisa un código contra la tienda dada y calcula su descuento.
     *
     * @param  string|int|float  $subtotal  subtotal del carrito en pesos (por ejemplo "1700.00")
     */
    public function validate(Store $store, ?string $code, string|int|float $subtotal, ?CarbonInterface $now = null): CouponResult
    {
        $now ??= now();
        $subtotalCents = $this->toCents($subtotal);
        $code = Str::upper(trim((string) $code));

        if ($code === '') {
            return CouponResult::invalid('Escribe un código de descuento.', $subtotalCents);
        }

        $coupon = Coupon::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->where('code', $code)
            ->first();

        if (! $coupon) {
            // Un código de la otra tienda recibe la misma respuesta que uno que no existe: no se revela qué códigos hay allá.
            return CouponResult::invalid('El código no es válido.', $subtotalCents);
        }

        if (! $coupon->is_active) {
            return CouponResult::invalid('El cupón no está activo.', $subtotalCents, $coupon);
        }

        if ($coupon->starts_at && $now->lt($coupon->starts_at)) {
            return CouponResult::invalid('Todavía no está vigente.', $subtotalCents, $coupon);
        }

        if ($coupon->ends_at && $now->gt($coupon->ends_at)) {
            return CouponResult::invalid('Venció.', $subtotalCents, $coupon);
        }

        if ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
            return CouponResult::invalid('Ya no tiene usos.', $subtotalCents, $coupon);
        }

        if ($subtotalCents <= 0) {
            return CouponResult::invalid('Agrega productos a tu carrito para usar un cupón.', $subtotalCents, $coupon);
        }

        return CouponResult::valid($coupon, $subtotalCents, $this->discountCents($coupon, $subtotalCents));
    }

    /** Descuento en pesos ("170.00") de un cupón sobre un subtotal; nunca pasa del subtotal. */
    public function discount(Coupon $coupon, string|int|float $subtotal): string
    {
        return CouponResult::money($this->discountCents($coupon, $this->toCents($subtotal)));
    }

    /**
     * Aplica un código: si es válido lo guarda en la sesión (reemplaza al anterior). Si no, deja el
     * cupón que ya hubiera aplicado y devuelve el motivo del rechazo.
     */
    public function apply(Store $store, ?string $code, string|int|float $subtotal): CouponResult
    {
        $result = $this->validate($store, $code, $subtotal);

        if ($result->valid) {
            session()->put(self::SESSION_KEY, ['store_id' => $store->id, 'code' => $result->coupon->code]);
        }

        return $result;
    }

    public function remove(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Cupón aplicado en la sesión, revalidado con el subtotal de ahora (por si venció, se agotó, se
     * apagó o el carrito cambió). Si dejó de valer se quita de la sesión y se devuelve el motivo.
     * Null si no hay cupón aplicado en esta tienda.
     */
    public function applied(Store $store, string|int|float $subtotal): ?CouponResult
    {
        $saved = session(self::SESSION_KEY);

        if (! is_array($saved) || ($saved['store_id'] ?? null) !== $store->id) {
            // Un cupón guardado para otra tienda no aplica aquí: se descarta.
            if ($saved !== null) {
                $this->remove();
            }

            return null;
        }

        $result = $this->validate($store, $saved['code'] ?? null, $subtotal);

        if (! $result->valid) {
            $this->remove();
        }

        return $result;
    }

    private function discountCents(Coupon $coupon, int $subtotalCents): int
    {
        $valueCents = $this->toCents($coupon->value);

        $discount = match ($coupon->type) {
            // value es el porcentaje (10.00 = 10 %); se redondea al centavo.
            CouponType::Percent => (int) round($subtotalCents * (float) $coupon->value / 100),
            CouponType::Fixed => $valueCents,
        };

        // Nunca negativo ni mayor al subtotal: el total no puede quedar por debajo de cero.
        return max(0, min($discount, $subtotalCents));
    }

    private function toCents(string|int|float|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
