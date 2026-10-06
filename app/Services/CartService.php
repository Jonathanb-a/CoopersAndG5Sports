<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * Carrito en la sesión, uno por tienda. En la sesión solo se guarda variant_id => cantidad: el precio,
 * el nombre, la foto y la disponibilidad se leen de la base de datos cada vez. Nunca se confía en
 * datos que manda el navegador.
 *
 * Aparte se recuerda el precio que el cliente vio de cada línea (shop.cart_prices.{tienda}), solo para
 * poder avisarle si cambió. Ese precio nunca se usa para cobrar.
 *
 * El carrito no descuenta ni reserva stock: eso pasa al apartar el pedido (StockService).
 */
class CartService
{
    private const CART_KEY = 'shop.cart.';

    private const SEEN_PRICES_KEY = 'shop.cart_prices.';

    /** Agrega piezas de una talla (se suman a las que ya hubiera). */
    public function add(Store $store, int $variantId, int $quantity = 1): void
    {
        if ($quantity < 1) {
            throw new CartException('La cantidad debe ser de al menos 1.');
        }

        $variant = $this->purchasable($store, $variantId);
        $cart = $this->cart($store);
        $existing = $cart[$variantId] ?? 0;

        $this->assertCanHold($variant, $existing + $quantity, $existing);

        $cart[$variantId] = $existing + $quantity;
        $this->saveCart($store, $cart);

        // El cliente acaba de ver el precio actual en la página del producto.
        $this->saveSeenPrices($store, [$variantId => $this->priceCents($variant->product)] + $this->seenPrices($store));
    }

    /** Cambia la cantidad de una línea. Con 0 la quita. */
    public function setQuantity(Store $store, int $variantId, int $quantity): void
    {
        if (! isset($this->cart($store)[$variantId])) {
            throw new CartException('Ese producto no está en tu carrito.');
        }

        if ($quantity <= 0) {
            $this->remove($store, $variantId);

            return;
        }

        $variant = $this->purchasable($store, $variantId);

        $this->assertCanHold($variant, $quantity);

        $cart = $this->cart($store);
        $cart[$variantId] = $quantity;
        $this->saveCart($store, $cart);
    }

    public function remove(Store $store, int $variantId): void
    {
        $cart = $this->cart($store);
        $prices = $this->seenPrices($store);

        unset($cart[$variantId], $prices[$variantId]);

        $this->saveCart($store, $cart);
        $this->saveSeenPrices($store, $prices);
    }

    public function clear(Store $store): void
    {
        session()->forget([self::CART_KEY.$store->id, self::SEEN_PRICES_KEY.$store->id]);
    }

    /**
     * Ids de las tallas que hay en el carrito (según la sesión).
     *
     * @return list<int>
     */
    public function variantIds(Store $store): array
    {
        return array_keys($this->cart($store));
    }

    /** Piezas en el carrito según la sesión (sin consultar la base de datos): para el contador del encabezado. */
    public function rawCount(Store $store): int
    {
        return (int) array_sum($this->cart($store));
    }

    /**
     * Contador y líneas para el encabezado y el mini carrito (se comparte en todas las páginas de la
     * tienda). Con el carrito vacío no consulta la base de datos.
     *
     * @return array{count: int, subtotal: string, lines: list<array<string, mixed>>}
     */
    public function summary(Store $store): array
    {
        if ($this->rawCount($store) === 0) {
            return ['count' => 0, 'subtotal' => '0.00', 'lines' => []];
        }

        $view = $this->view($store);

        return ['count' => $view->count(), 'subtotal' => $view->subtotal(), 'lines' => $view->lines];
    }

    /** El carrito como está hoy en la base de datos, sin modificar nada. Las líneas que ya no se pueden comprar no salen. */
    public function view(Store $store): CartView
    {
        return $this->resolve($store, persist: false);
    }

    /**
     * Igual que view(), pero corrige la sesión: quita lo que ya no está disponible o se agotó, baja las
     * cantidades que pasan del stock y avisa de los cambios de precio. Los avisos vienen en el resultado.
     */
    public function reconcile(Store $store): CartView
    {
        return $this->resolve($store, persist: true);
    }

    private function resolve(Store $store, bool $persist): CartView
    {
        $cart = $this->cart($store);

        if ($cart === []) {
            return new CartView([], 0);
        }

        // Con borrados incluidos, para poder nombrar en el aviso lo que se quitó.
        $variants = ProductVariant::withTrashed()
            ->with(['product' => fn ($query) => $query->withoutGlobalScope('store')->withTrashed()->with(['images', 'brand'])])
            ->whereIn('id', array_keys($cart))
            ->get()
            ->keyBy('id');

        $seenPrices = $this->seenPrices($store);
        $max = $this->maxQuantity();
        $keptCart = [];
        $keptPrices = [];
        $lines = [];
        $notices = [];
        $subtotal = 0;

        foreach ($cart as $variantId => $quantity) {
            $variant = $variants->get($variantId);
            $product = $variant?->product;
            $ours = $variant && $product && $product->store_id === $store->id;
            $label = $ours ? "{$product->name} (talla {$variant->size})" : null;

            if (! $ours || $variant->trashed() || $product->trashed() || ! $product->is_active) {
                $notices[] = $label
                    ? "{$label} ya no está disponible y lo quitamos de tu carrito."
                    : 'Un producto de tu carrito ya no está disponible y lo quitamos.';

                continue;
            }

            $stock = max(0, $variant->stock);
            $allowed = $product->allow_backorder ? $max : min($stock, $max);

            if ($allowed < 1) {
                $notices[] = "{$label} se agotó y lo quitamos de tu carrito.";

                continue;
            }

            if ($quantity > $allowed) {
                $notices[] = $product->allow_backorder
                    ? "Ajustamos {$label}: puedes llevar máximo {$max} piezas."
                    : 'Solo '.$this->pieces($allowed, 'queda', 'quedan')." de {$label}: ajustamos la cantidad.";
                $quantity = $allowed;
            }

            $price = $this->priceCents($product);
            $seen = $seenPrices[$variantId] ?? null;

            if ($seen !== null && $seen !== $price) {
                $notices[] = "El precio de {$label} cambió de ".Number::currency($seen / 100).' a '.Number::currency($price / 100).'.';
            }

            $backorderQuantity = $product->allow_backorder ? max(0, $quantity - $stock) : 0;
            $path = $product->images->first()?->path;

            $lines[] = [
                'variant_id' => $variantId,
                'product_id' => $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'brand' => $product->brand?->name,
                'color' => $product->color,
                'size' => $variant->size,
                'image_url' => $path ? Storage::disk('public')->url($path) : null,
                'unit_price' => CouponResult::money($price),
                'quantity' => $quantity,
                'line_total' => CouponResult::money($price * $quantity),
                'backorder_quantity' => $backorderQuantity,
                'backorder_days' => $backorderQuantity > 0 ? $product->backorder_days : null,
                'max_quantity' => $allowed,
            ];

            $subtotal += $price * $quantity;
            $keptCart[$variantId] = $quantity;
            $keptPrices[$variantId] = $price;
        }

        if ($persist) {
            $this->saveCart($store, $keptCart);
            $this->saveSeenPrices($store, $keptPrices);
        }

        return new CartView($lines, $subtotal, $notices);
    }

    /**
     * La talla, solo si se puede comprar en esta tienda: de un producto de la tienda, activo y sin
     * borrar. Una talla de otra tienda, inactiva o borrada se rechaza con el mismo mensaje.
     */
    private function purchasable(Store $store, int $variantId): ProductVariant
    {
        $variant = ProductVariant::query()
            ->with(['product' => fn ($query) => $query->withoutGlobalScope('store')])
            ->find($variantId);

        $product = $variant?->product;

        if (! $variant || ! $product || $product->store_id !== $store->id || ! $product->is_active) {
            throw new CartException('Este producto no está disponible.');
        }

        return $variant;
    }

    /** Revisa que la talla aguante esa cantidad: tope por línea y, sin sobre pedido, el stock. */
    private function assertCanHold(ProductVariant $variant, int $quantity, int $existing = 0): void
    {
        $max = $this->maxQuantity();

        if ($quantity > $max) {
            throw new CartException("Puedes llevar máximo {$max} piezas de cada producto.");
        }

        if ($variant->product->allow_backorder) {
            return;
        }

        $stock = max(0, $variant->stock);

        if ($stock === 0) {
            throw new CartException("La talla {$variant->size} está agotada.");
        }

        if ($quantity > $stock) {
            $left = $this->pieces($stock, 'queda', 'quedan');

            throw new CartException($existing > 0
                ? "Ya tienes {$existing} en tu carrito y solo {$left} en talla {$variant->size}."
                : "Solo {$left} en talla {$variant->size}.");
        }
    }

    /** "1 pieza" con "queda", "3 piezas" con "quedan". */
    private function pieces(int $count, string $one, string $many): string
    {
        return $count === 1 ? "{$one} 1 pieza" : "{$many} {$count} piezas";
    }

    private function maxQuantity(): int
    {
        return max(1, (int) config('shop.max_line_quantity', 10));
    }

    private function priceCents(Product $product): int
    {
        return (int) round(((float) $product->price) * 100);
    }

    /** @return array<int, int> variant_id => cantidad */
    private function cart(Store $store): array
    {
        $cart = session(self::CART_KEY.$store->id, []);

        // Solo enteros positivos: lo que no cuadre se descarta.
        return collect(is_array($cart) ? $cart : [])
            ->mapWithKeys(fn ($quantity, $variantId) => [(int) $variantId => (int) $quantity])
            ->filter(fn (int $quantity, int $variantId) => $quantity > 0 && $variantId > 0)
            ->all();
    }

    /** @param  array<int, int>  $cart */
    private function saveCart(Store $store, array $cart): void
    {
        $cart === []
            ? session()->forget(self::CART_KEY.$store->id)
            : session()->put(self::CART_KEY.$store->id, $cart);
    }

    /** @return array<int, int> variant_id => precio en centavos que el cliente vio */
    private function seenPrices(Store $store): array
    {
        $prices = session(self::SEEN_PRICES_KEY.$store->id, []);

        return collect(is_array($prices) ? $prices : [])
            ->mapWithKeys(fn ($cents, $variantId) => [(int) $variantId => (int) $cents])
            ->all();
    }

    /** @param  array<int, int>  $prices */
    private function saveSeenPrices(Store $store, array $prices): void
    {
        $prices === []
            ? session()->forget(self::SEEN_PRICES_KEY.$store->id)
            : session()->put(self::SEEN_PRICES_KEY.$store->id, $prices);
    }
}
