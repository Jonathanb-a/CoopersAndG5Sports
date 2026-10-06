<?php

namespace App\Services;

use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
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
     * @throws ReservationLimitException si el cliente ya tiene el máximo de apartados sin pagar en la tienda
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

                // Con la tienda bloqueada: dos pedidos a la vez del mismo cliente no se saltan el límite.
                if ($message = $this->reservationLimitMessage($store, $customer)) {
                    throw new ReservationLimitException($message);
                }

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
     * Venta de mostrador: el cliente paga y se lleva las piezas en ese momento, así que el pedido nace
     * entregado, sin cliente, y descuenta el stock (venta_mostrador), todo en una sola transacción con
     * las tallas bloqueadas. No hay sobre pedido: sin stock suficiente no se registra nada.
     *
     * Los precios se leen de la base de datos; de cada línea solo se toman la talla y la cantidad.
     *
     * @param  list<array{variant_id: int|string, quantity: int|string}>  $lines
     * @param  string|int|float|null  $discount  descuento general en pesos; exige nota y no puede superar el subtotal
     * @param  string|null  $note  nota de la venta (obligatoria si hay descuento)
     *
     * @throws CounterSaleException
     */
    public function placeCounterSale(
        Store $store,
        User $user,
        array $lines,
        PaymentMethod $paymentMethod,
        string|int|float|null $discount = null,
        ?string $note = null,
    ): Order {
        // Con la tienda recién leída: que siga activa y que quien vende la atienda.
        if (! $user->canAccessTenant(Store::query()->findOrFail($store->id))) {
            throw new CounterSaleException("No atiendes la tienda {$store->name}.");
        }

        // Una talla repetida en dos líneas es una sola línea con la suma.
        $quantities = [];

        foreach ($lines as $line) {
            $variantId = (int) ($line['variant_id'] ?? 0);
            $quantity = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT);

            if ($variantId < 1) {
                throw new CounterSaleException('Elige el producto y la talla de cada línea.');
            }

            if ($quantity === false || $quantity < 1) {
                throw new CounterSaleException('La cantidad de cada línea debe ser de al menos 1 pieza.');
            }

            $quantities[$variantId] = ($quantities[$variantId] ?? 0) + $quantity;
        }

        if ($quantities === []) {
            throw new CounterSaleException('Agrega al menos un producto a la venta.');
        }

        $note = filled($note) ? trim($note) : null;
        $discountCents = $this->toCents(is_numeric($discount) ? $discount : 0);

        if ($discount !== null && $discount !== '' && (! is_numeric($discount) || $discountCents < 0)) {
            throw new CounterSaleException('El descuento debe ser un monto en pesos, de 0 en adelante.');
        }

        if ($discountCents > 0 && $note === null) {
            throw new CounterSaleException('Escribe una nota que explique el descuento.');
        }

        try {
            return DB::transaction(function () use ($store, $user, $quantities, $paymentMethod, $discountCents, $note) {
                // Mismo orden de bloqueos que en la tienda en línea: tienda (número consecutivo) y tallas.
                Store::query()->whereKey($store->id)->lockForUpdate()->first();

                $variants = ProductVariant::query()
                    ->whereIn('id', array_keys($quantities))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $products = Product::withoutGlobalScopes()
                    ->whereIn('id', $variants->pluck('product_id'))
                    ->get()
                    ->keyBy('id');

                $subtotal = 0;
                $items = [];

                foreach ($quantities as $variantId => $quantity) {
                    $variant = $variants->get($variantId);
                    $product = $variant ? $products->get($variant->product_id) : null;

                    // Borrada, de otra tienda o apagada: para el mostrador es lo mismo, no se vende.
                    if ($product === null || $product->store_id !== $store->id || ! $product->is_active) {
                        throw new CounterSaleException("Una de las tallas elegidas no está a la venta en {$store->name}.");
                    }

                    if ($variant->stock < $quantity) {
                        throw new CounterSaleException(
                            "No hay stock suficiente de {$product->name} (talla {$variant->size}): "
                            .($variant->stock === 1 ? 'queda 1 pieza' : "quedan {$variant->stock} piezas")." y la venta pide {$quantity}."
                        );
                    }

                    $subtotal += $this->toCents($product->price) * $quantity;
                    $items[] = [$variant, $quantity, [
                        'product_variant_id' => $variant->id,
                        'product_name' => $product->name,
                        'size' => $variant->size,
                        'unit_price' => $product->price,
                        'quantity' => $quantity,
                        'is_backorder' => false,
                    ]];
                }

                if ($discountCents > $subtotal) {
                    throw new CounterSaleException(
                        'El descuento ('.CouponResult::money($discountCents).') no puede ser mayor que el subtotal ('.CouponResult::money($subtotal).').'
                    );
                }

                $order = Order::create([
                    'store_id' => $store->id,
                    'customer_id' => null,
                    'number' => $this->nextNumber($store),
                    'channel' => OrderChannel::Counter,
                    'status' => OrderStatus::Delivered,
                    'delivery_method' => DeliveryMethod::Pickup,
                    'subtotal' => CouponResult::money($subtotal),
                    'discount' => CouponResult::money($discountCents),
                    'shipping_cost' => CouponResult::money(0),
                    'total' => CouponResult::money($subtotal - $discountCents),
                    'payment_method' => $paymentMethod,
                    'paid_at' => now(),
                    'delivered_at' => now(),
                    'internal_notes' => $note,
                ]);

                // Primer renglón del historial: nace entregado, lo registró quien atendió.
                $this->statuses->recordInitial($order, $user, trim("Venta de mostrador. Pago: {$paymentMethod->getLabel()}. ".($note ?? '')));

                foreach ($items as [$variant, $quantity, $item]) {
                    $order->items()->create($item);

                    $this->stock->change($variant, StockMovementType::CounterSale, -$quantity, $user, "Venta de mostrador {$order->number}", $order);
                }

                return $order;
            });
        } catch (InsufficientStockException) {
            // No debería pasar (las tallas están bloqueadas y recién revisadas), pero el stock nunca queda negativo.
            throw new CounterSaleException('El stock cambió mientras se registraba la venta. Revisa las piezas e inténtalo de nuevo.');
        }
    }

    /**
     * Aviso para el cliente que ya llegó al máximo de pedidos apartados sin pagar en esta tienda
     * (shop.max_open_reservations). Null si todavía puede apartar. Los de la otra tienda no cuentan.
     */
    public function reservationLimitMessage(Store $store, Customer $customer): ?string
    {
        $open = Order::withoutGlobalScopes()
            ->where('store_id', $store->id)
            ->where('customer_id', $customer->id)
            ->where('status', OrderStatus::Reserved->value)
            ->count();

        if ($open < (int) config('shop.max_open_reservations', 3)) {
            return null;
        }

        return "Ya tienes {$open} pedidos apartados sin pagar en {$store->name}. Paga o cancela alguno para poder apartar otro.";
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
