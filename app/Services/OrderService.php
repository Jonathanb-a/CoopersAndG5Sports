<?php

namespace App\Services;

use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Pedidos de la tienda en línea: aparta lo que hay en el carrito del cliente. */
class OrderService
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CouponService $coupons,
        private readonly StockService $stock,
        private readonly OrderStatusService $statuses,
    ) {}

    /**
     * Métodos de entrega que ofrece la tienda con su costo en centavos. Recoger en tienda siempre
     * está y es gratis; local y nacional solo si la tienda les puso costo (vacío = no se ofrece).
     *
     * @return array<string, int> método => costo en centavos
     */
    public function deliveryOptions(Store $store): array
    {
        $options = [DeliveryMethod::Pickup->value => 0];

        if ($store->local_shipping_cost !== null) {
            $options[DeliveryMethod::Local->value] = $this->toCents($store->local_shipping_cost);
        }

        if ($store->national_shipping_cost !== null) {
            $options[DeliveryMethod::National->value] = $this->toCents($store->national_shipping_cost);
        }

        return $options;
    }

    /**
     * Aparta el carrito del cliente: crea el pedido (apartado, vence en shop.reserve_hours) y descuenta
     * el stock, todo en una sola transacción.
     *
     * Antes revalida carrito, cupón y stock con las filas bloqueadas. Si algo cambió (una talla se
     * agotó, bajó el stock, cambió un precio, el cupón dejó de valer) no crea nada y lanza
     * CartChangedException con los avisos; el carrito de la sesión queda ya corregido.
     *
     * @param  array<string, mixed>|null  $shippingAddress  dirección de entrega (null al recoger en tienda)
     *
     * @throws CartChangedException
     */
    public function place(
        Store $store,
        Customer $customer,
        DeliveryMethod $method,
        ?array $shippingAddress,
        string $phone,
        ?string $notes = null,
    ): Order {
        $shipping = $this->deliveryOptions($store)[$method->value]
            ?? throw new InvalidArgumentException("La tienda no ofrece el método de entrega [{$method->value}].");

        try {
            $order = DB::transaction(function () use ($store, $customer, $method, $shippingAddress, $phone, $notes, $shipping) {
                // Bloqueos primero y siempre en el mismo orden (tienda, tallas, cupón): dos pedidos de la
                // misma tienda entran uno tras otro, así el número es consecutivo y nadie se lleva la
                // misma pieza. Todo lo que se lee después ya ve los datos definitivos.
                Store::query()->whereKey($store->id)->lockForUpdate()->first();

                ProductVariant::query()
                    ->whereIn('id', $this->cart->variantIds($store))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $couponCode = session(CouponService::SESSION_KEY.'.code');

                if ($couponCode) {
                    Coupon::withoutGlobalScopes()->where('store_id', $store->id)->where('code', $couponCode)->lockForUpdate()->first();
                }

                // Revalida el carrito contra la base de datos. Cualquier corrección cancela el apartado.
                $view = $this->cart->reconcile($store);

                if ($view->isEmpty() || $view->notices !== []) {
                    throw new CartChangedException($view->notices ?: ['Tu carrito está vacío.']);
                }

                $applied = $this->coupons->applied($store, $view->subtotal());

                if ($applied && ! $applied->valid) {
                    throw new CartChangedException(["Quitamos el cupón {$couponCode}: {$applied->reason}"]);
                }

                $subtotal = $view->subtotalCents;
                $discount = $applied?->discountCents ?? 0;

                $order = Order::create([
                    'store_id' => $store->id,
                    'customer_id' => $customer->id,
                    'number' => $this->nextNumber($store),
                    'channel' => OrderChannel::Web,
                    'status' => OrderStatus::Reserved,
                    'delivery_method' => $method,
                    'shipping_address' => $method === DeliveryMethod::Pickup ? null : $shippingAddress,
                    'subtotal' => CouponResult::money($subtotal),
                    'discount' => CouponResult::money($discount),
                    'shipping_cost' => CouponResult::money($shipping),
                    'total' => CouponResult::money($subtotal - $discount + $shipping),
                    'coupon_id' => $applied?->coupon->id,
                    'expires_at' => now()->addHours((int) config('shop.reserve_hours', 48)),
                    'notes' => filled($notes) ? trim($notes) : null,
                ]);

                // Primer renglón del historial: sin estado anterior → apartado, hecho por el cliente.
                $this->statuses->recordInitial($order, $customer);

                foreach ($view->lines as $line) {
                    // La parte con stock se aparta; lo que falta va en una línea aparte, sobre pedido.
                    $backorder = $line['backorder_quantity'];
                    $inStock = $line['quantity'] - $backorder;
                    $copy = [
                        'product_variant_id' => $line['variant_id'],
                        'product_name' => $line['name'],
                        'size' => $line['size'],
                        'unit_price' => $line['unit_price'],
                    ];

                    if ($inStock > 0) {
                        $order->items()->create([...$copy, 'quantity' => $inStock, 'is_backorder' => false]);

                        $this->stock->change(
                            ProductVariant::findOrFail($line['variant_id']),
                            StockMovementType::WebSale,
                            -$inStock,
                            notes: "Pedido {$order->number}",
                            order: $order,
                        );
                    }

                    if ($backorder > 0) {
                        $order->items()->create([...$copy, 'quantity' => $backorder, 'is_backorder' => true]);
                    }
                }

                if ($applied) {
                    $applied->coupon->increment('used_count');
                }

                $customer->update(['phone' => $phone]);

                return $order;
            });
        } catch (InsufficientStockException) {
            // No debería pasar (las tallas están bloqueadas y recién revisadas), pero el stock nunca queda negativo.
            throw new CartChangedException(['El stock cambió mientras apartábamos tu pedido. Revisa tu carrito e inténtalo de nuevo.']);
        }

        $this->cart->clear($store);
        $this->coupons->remove();

        return $order;
    }

    /**
     * Siguiente número legible de la tienda: CP-00042, G5-00042. Se llama con la tienda bloqueada,
     * así que no hay dos pedidos pidiendo número a la vez.
     */
    private function nextNumber(Store $store): string
    {
        $prefix = config("shop.order_prefixes.{$store->slug}")
            ?: Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $store->slug));

        $last = Order::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->where('number', 'like', $prefix.'-%')
            ->orderByDesc('id')
            ->value('number');

        $next = $last && preg_match('/-(\d+)$/', $last, $match) ? (int) $match[1] + 1 : 1;

        do {
            $number = sprintf('%s-%05d', $prefix, $next++);
        } while (Order::withoutGlobalScopes()->where('number', $number)->exists());

        return $number;
    }

    private function toCents(string|int|float $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
