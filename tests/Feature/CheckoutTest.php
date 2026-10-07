<?php

namespace Tests\Feature;

use App\Enums\CouponType;
use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\SizeType;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Filament\Pages\Tenancy\EditStoreProfile;
use App\Models\Address;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\CouponService;
use App\Services\OrderService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const COPPERS = 'http://coppers.localhost';

    private const G5 = 'http://g5.localhost';

    private Store $coppers;

    private Store $g5;

    private Customer $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->coppers = Store::create([
            'name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers',
            'bank_info' => "BBVA\nCLABE 012345678901234567", 'local_shipping_cost' => 80, 'national_shipping_cost' => 250,
        ]);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);

        $this->ana = Customer::factory()->create(['name' => 'Ana Pérez']);
    }

    /** @param array<string, int> $sizes talla => stock */
    private function product(Store $store, string $name, array $sizes = ['M' => 5], array $extra = []): Product
    {
        $category = Category::firstOrCreate(
            ['store_id' => $store->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug(),
            'price' => 500,
            ...$extra,
        ]);

        foreach ($sizes as $size => $stock) {
            $product->variants()->create(['size' => (string) $size, 'stock' => $stock]);
        }

        return $product;
    }

    private function variant(Product $product): ProductVariant
    {
        return $product->variants()->orderBy('id')->firstOrFail();
    }

    private function addToCart(ProductVariant $variant, int $quantity = 1, string $host = self::COPPERS): void
    {
        $this->post("{$host}/carrito", ['variant_id' => $variant->id, 'quantity' => $quantity])->assertSessionHasNoErrors();
    }

    /** Aparta el carrito como Ana (recoger en tienda si no se dice otra cosa). */
    private function place(array $data = [], ?Customer $customer = null, string $host = self::COPPERS)
    {
        return $this->actingAs($customer ?? $this->ana, 'customer')->post("{$host}/checkout", [
            'delivery_method' => 'recoger',
            'phone' => '755 123 4567',
            ...$data,
        ]);
    }

    /** Datos del checkout para una entrega local en Zihuatanejo. */
    private function local(array $extra = []): array
    {
        return [
            'delivery_method' => 'local',
            'local' => ['name' => 'Ana Pérez', 'point' => 'Calle Hidalgo 5, Centro', 'references' => 'Portón negro', ...$extra],
        ];
    }

    private function addressData(array $extra = []): array
    {
        return ['street' => 'Calle Hidalgo 5', 'neighborhood' => 'Centro', 'city' => 'Zihuatanejo', 'state' => 'Guerrero', 'zip' => '40880', 'notes' => 'Portón negro', ...$extra];
    }

    // ---- 1. Apartar descuenta el stock ---------------------------------------------------------

    public function test_placing_an_order_takes_the_stock_and_leaves_web_sale_movements_linked_to_the_order()
    {
        $shirt = $this->variant($this->product($this->coppers, 'Camisa Oxford', ['M' => 5], ['price' => 1700]));
        $pants = $this->variant($this->product($this->coppers, 'Pantalón', ['32' => 2], ['price' => 900]));
        $this->addToCart($shirt, 2);
        $this->addToCart($pants, 1);

        $this->travelTo(now()->startOfMinute());

        $this->place(['notes' => '  Tocar el timbre  '])->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');

        $order = Order::withoutGlobalScopes()->sole();

        $this->assertSame('CP-00001', $order->number);
        $this->assertSame($this->coppers->id, $order->store_id);
        $this->assertSame($this->ana->id, $order->customer_id);
        $this->assertSame(OrderChannel::Web, $order->channel);
        $this->assertSame(OrderStatus::Reserved, $order->status);
        $this->assertSame(DeliveryMethod::Pickup, $order->delivery_method);
        $this->assertTrue($order->expires_at->equalTo(now()->addHours(48)));
        $this->assertNull($order->paid_at);
        $this->assertSame('Tocar el timbre', $order->notes);
        $this->assertSame('4300.00', $order->subtotal);
        $this->assertSame('4300.00', $order->total);

        // Stock descontado...
        $this->assertSame(3, $shirt->fresh()->stock);
        $this->assertSame(1, $pants->fresh()->stock);

        // ...con un movimiento venta_web por línea, ligado al pedido.
        $movements = StockMovement::where('order_id', $order->id)->get()->keyBy('product_variant_id');

        $this->assertCount(2, $movements);
        $this->assertSame(StockMovementType::WebSale, $movements[$shirt->id]->type);
        $this->assertSame(-2, $movements[$shirt->id]->quantity);
        $this->assertSame(-1, $movements[$pants->id]->quantity);
        $this->assertNull($movements[$shirt->id]->user_id);
        $this->assertSame(2, StockMovement::count());

        // Las líneas guardan nombre, talla y precio del momento.
        $items = $order->items()->get()->keyBy('product_variant_id');

        $this->assertCount(2, $items);
        $this->assertSame(['Camisa Oxford', 'M', '1700.00', 2, false], [$items[$shirt->id]->product_name, $items[$shirt->id]->size, $items[$shirt->id]->unit_price, $items[$shirt->id]->quantity, $items[$shirt->id]->is_backorder]);
        $this->assertSame(['Pantalón', '32', '900.00', 1], [$items[$pants->id]->product_name, $items[$pants->id]->size, $items[$pants->id]->unit_price, $items[$pants->id]->quantity]);

        // El carrito queda vacío.
        $this->assertNull(session('shop.cart.'.$this->coppers->id));
    }

    public function test_the_order_keeps_its_own_copy_of_name_and_price()
    {
        $product = $this->product($this->coppers, 'Camisa Oxford', ['M' => 5], ['price' => 1700]);
        $this->addToCart($this->variant($product));
        $this->place();

        $product->update(['name' => 'Otro nombre', 'price' => 5]);

        $item = OrderItem::sole();

        $this->assertSame('Camisa Oxford', $item->product_name);
        $this->assertSame('1700.00', $item->unit_price);
        $this->assertSame('1700.00', Order::withoutGlobalScopes()->sole()->total);
    }

    // ---- 2. Se agota antes de confirmar ------------------------------------------------------

    public function test_if_a_size_sells_out_before_confirming_no_order_is_created_and_nothing_is_taken()
    {
        $shirt = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5]));
        $pants = $this->variant($this->product($this->coppers, 'Pantalón', ['32' => 2]));
        $this->addToCart($shirt, 2);
        $this->addToCart($pants, 1);

        // Alguien más se llevó el último pantalón.
        $pants->update(['stock' => 0]);

        $this->place()
            ->assertRedirect(self::COPPERS.'/carrito')
            ->assertSessionHas('cart_notices', ['Pantalón (talla 32) se agotó y lo quitamos de tu carrito.']);

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(5, $shirt->fresh()->stock);
        $this->assertSame(0, $pants->fresh()->stock);

        // El carrito conserva lo que sí se puede comprar, y /carrito muestra el aviso.
        $this->assertSame([$shirt->id => 2], session('shop.cart.'.$this->coppers->id));
        $this->get(self::COPPERS.'/carrito')->assertInertia(fn (Assert $page) => $page
            ->where('notices', ['Pantalón (talla 32) se agotó y lo quitamos de tu carrito.'])
            ->has('cart.lines', 1));
    }

    public function test_if_the_stock_drops_below_the_quantity_no_order_is_created()
    {
        $shirt = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5]));
        $this->addToCart($shirt, 4);

        $shirt->update(['stock' => 1]);

        $this->place()
            ->assertRedirect(self::COPPERS.'/carrito')
            ->assertSessionHas('cart_notices', ['Solo queda 1 pieza de Camisa (talla M): ajustamos la cantidad.']);

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(1, $shirt->fresh()->stock);
    }

    public function test_if_the_price_changed_since_the_customer_saw_it_no_order_is_created()
    {
        $product = $this->product($this->coppers, 'Camisa', ['M' => 5], ['price' => 1000]);
        $this->addToCart($this->variant($product));

        $product->update(['price' => 1500]);

        $this->place()
            ->assertRedirect(self::COPPERS.'/carrito')
            ->assertSessionHas('cart_notices', ['El precio de Camisa (talla M) cambió de $1,000.00 a $1,500.00.']);

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(5, $this->variant($product)->stock);

        // Ya avisado: al confirmar de nuevo se aparta con el precio nuevo.
        $this->place()->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');
        $this->assertSame('1500.00', Order::withoutGlobalScopes()->sole()->total);
    }

    public function test_a_product_turned_off_before_confirming_stops_the_order()
    {
        $product = $this->product($this->coppers, 'Camisa', ['M' => 5]);
        $this->addToCart($this->variant($product));
        $product->update(['is_active' => false]);

        $this->place()->assertRedirect(self::COPPERS.'/carrito')->assertSessionHas('cart_notices');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, StockMovement::count());
    }

    // ---- 3. Sobre pedido -----------------------------------------------------------------------

    public function test_a_backorder_line_above_the_stock_is_split_in_two()
    {
        $product = $this->product($this->coppers, 'Camisa', ['M' => 2], ['price' => 500, 'allow_backorder' => true, 'backorder_days' => 7]);
        $variant = $this->variant($product);
        $this->addToCart($variant, 5);

        $this->place()->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');

        $order = Order::withoutGlobalScopes()->sole();
        $items = $order->items()->orderBy('id')->get();

        $this->assertCount(2, $items);
        $this->assertSame([2, false], [$items[0]->quantity, $items[0]->is_backorder]);
        $this->assertSame([3, true], [$items[1]->quantity, $items[1]->is_backorder]);
        $this->assertSame($variant->id, $items[1]->product_variant_id);
        $this->assertSame('500.00', $items[1]->unit_price);

        // Solo la parte con stock descuenta.
        $this->assertSame(0, $variant->fresh()->stock);
        $movement = StockMovement::sole();
        $this->assertSame(-2, $movement->quantity);
        $this->assertSame($order->id, $movement->order_id);

        // El total cobra las 5 piezas.
        $this->assertSame('2500.00', $order->total);
    }

    public function test_a_line_with_no_stock_at_all_is_only_backorder_and_takes_nothing()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 0], ['allow_backorder' => true]));
        $this->addToCart($variant, 2);

        $this->place()->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');

        $item = OrderItem::sole();

        $this->assertSame([2, true], [$item->quantity, $item->is_backorder]);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, $variant->fresh()->stock);
    }

    public function test_a_backorder_product_inside_its_stock_is_a_normal_line()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5], ['allow_backorder' => true]));
        $this->addToCart($variant, 3);

        $this->place();

        $item = OrderItem::sole();

        $this->assertSame([3, false], [$item->quantity, $item->is_backorder]);
        $this->assertSame(2, $variant->fresh()->stock);
    }

    // ---- 4. La última pieza ---------------------------------------------------------------------

    public function test_two_orders_for_the_last_piece_only_one_is_created()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 1]));
        $luis = Customer::factory()->create();

        // Ana y Luis tienen la última pieza en su carrito (cada quien en su sesión) y confirman uno tras otro.
        $this->addToCart($variant);
        $cartKey = 'shop.cart.'.$this->coppers->id;
        $bothCarts = session($cartKey);

        $this->place()->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');

        session()->put($cartKey, $bothCarts);

        $this->place(customer: $luis)
            ->assertRedirect(self::COPPERS.'/carrito')
            ->assertSessionHas('cart_notices', ['Camisa (talla M) se agotó y lo quitamos de tu carrito.']);

        $this->assertSame(1, Order::withoutGlobalScopes()->count());
        $this->assertSame($this->ana->id, Order::withoutGlobalScopes()->sole()->customer_id);
        $this->assertSame(0, $variant->fresh()->stock);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(-1, (int) StockMovement::sum('quantity'));
    }

    // ---- 5. Solo el dueño ve su pedido ---------------------------------------------------------

    public function test_a_customer_can_not_see_another_customers_order()
    {
        $this->addToCart($this->variant($this->product($this->coppers, 'Camisa')));
        $this->place();
        $luis = Customer::factory()->create();

        $this->actingAs($luis, 'customer')->get(self::COPPERS.'/pedido/CP-00001/gracias')->assertNotFound();
        $this->actingAs($this->ana, 'customer')->get(self::COPPERS.'/pedido/CP-00001/gracias')->assertOk();

        // Tampoco desde la otra tienda, ni un número que no existe.
        $this->actingAs($this->ana, 'customer')->get(self::G5.'/pedido/CP-00001/gracias')->assertNotFound();
        $this->actingAs($this->ana, 'customer')->get(self::COPPERS.'/pedido/CP-99999/gracias')->assertNotFound();
    }

    public function test_guests_and_panel_users_can_not_see_the_confirmation()
    {
        $this->addToCart($this->variant($this->product($this->coppers, 'Camisa')));
        $this->place();

        auth('customer')->logout();

        $this->get(self::COPPERS.'/pedido/CP-00001/gracias')->assertRedirect(self::COPPERS.'/login');

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner, 'web')->get(self::COPPERS.'/pedido/CP-00001/gracias')->assertRedirect(self::COPPERS.'/login');
    }

    public function test_the_confirmation_shows_number_summary_bank_data_and_deadline()
    {
        $this->travelTo('2026-10-05 10:30:00');
        $this->addToCart($this->variant($this->product($this->coppers, 'Camisa Oxford', ['M' => 5], ['price' => 1700])), 2);
        $this->place([...$this->local()]);

        $this->actingAs($this->ana, 'customer')->get(self::COPPERS.'/pedido/CP-00001/gracias')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('order-thanks')
                ->where('order.number', 'CP-00001')
                ->where('order.status', 'Apartado')
                ->where('order.subtotal', '3400.00')
                ->where('order.shipping_cost', '80.00')
                ->where('order.total', '3480.00')
                ->where('order.delivery_method', 'local')
                ->where('order.shipping_address.street', 'Calle Hidalgo 5, Centro')
                ->where('order.expires_at_text', 'miércoles 7 de octubre de 2026, 10:30')
                ->has('order.items', 1)
                ->where('order.items.0.name', 'Camisa Oxford')
                ->where('order.items.0.quantity', 2)
                ->where('order.items.0.line_total', '3400.00')
                ->where('order.items.0.is_backorder', false)
                ->where('bankInfo', "BBVA\nCLABE 012345678901234567"));
    }

    // ---- 6. El total ---------------------------------------------------------------------------

    public function test_the_saved_total_is_subtotal_minus_discount_plus_shipping()
    {
        $this->addToCart($this->variant($this->product($this->coppers, 'Camisa', ['M' => 5], ['price' => 500])), 2);
        $this->addToCart($this->variant($this->product($this->coppers, 'Calcetas', ['U' => 5], ['price' => 99.90])), 3);
        $coupon = Coupon::create(['store_id' => $this->coppers->id, 'code' => 'VERANO', 'type' => CouponType::Percent, 'value' => 15, 'max_uses' => 10, 'used_count' => 4]);
        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'VERANO'])->assertSessionHasNoErrors();

        $this->place($this->local())
            ->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');

        $order = Order::withoutGlobalScopes()->sole();

        // 2 × 500 + 3 × 99.90 = 1299.70; 15 % = 194.955 → 194.96; entrega local 80.
        $this->assertSame('1299.70', $order->subtotal);
        $this->assertSame('194.96', $order->discount);
        $this->assertSame('80.00', $order->shipping_cost);
        $this->assertSame('1184.74', $order->total);
        $this->assertEqualsWithDelta((float) $order->subtotal - (float) $order->discount + (float) $order->shipping_cost, (float) $order->total, 0.001);

        // El subtotal coincide con las líneas guardadas.
        $lines = $order->items->sum(fn (OrderItem $item) => (int) round((float) $item->unit_price * 100) * $item->quantity);
        $this->assertSame(129970, $lines);

        // El cupón queda ligado y con un uso más; ya no está en la sesión.
        $this->assertSame($coupon->id, $order->coupon_id);
        $this->assertSame(5, $coupon->fresh()->used_count);
        $this->assertNull(session(CouponService::SESSION_KEY));
    }

    public function test_a_fixed_coupon_bigger_than_the_subtotal_leaves_only_the_shipping_to_pay()
    {
        $this->addToCart($this->variant($this->product($this->coppers, 'Calcetas', ['U' => 5], ['price' => 80])));
        Coupon::create(['store_id' => $this->coppers->id, 'code' => 'GRANDE', 'type' => CouponType::Fixed, 'value' => 500]);
        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'GRANDE']);

        $this->place([...$this->local()]);

        $order = Order::withoutGlobalScopes()->sole();

        $this->assertSame(['80.00', '80.00', '80.00', '80.00'], [$order->subtotal, $order->discount, $order->shipping_cost, $order->total]);
    }

    public function test_the_browser_can_not_set_prices_totals_or_shipping()
    {
        $this->addToCart($this->variant($this->product($this->coppers, 'Camisa', ['M' => 5], ['price' => 500])));

        $this->place([
            ...$this->local(),
            'total' => 1, 'subtotal' => 1, 'discount' => 499, 'shipping_cost' => 0, 'status' => 'pagado', 'number' => 'HACK-1', 'customer_id' => 999,
        ]);

        $order = Order::withoutGlobalScopes()->sole();

        $this->assertSame(['500.00', '0.00', '80.00', '580.00'], [$order->subtotal, $order->discount, $order->shipping_cost, $order->total]);
        $this->assertSame(OrderStatus::Reserved, $order->status);
        $this->assertSame('CP-00001', $order->number);
        $this->assertSame($this->ana->id, $order->customer_id);
    }

    // ---- Cupón al apartar ----------------------------------------------------------------------

    public function test_a_coupon_that_expired_before_confirming_stops_the_order()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa'));
        $this->addToCart($variant);
        $coupon = Coupon::create(['store_id' => $this->coppers->id, 'code' => 'VERANO', 'type' => CouponType::Percent, 'value' => 10]);
        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'VERANO']);

        $coupon->update(['ends_at' => now()->subMinute()]);

        $this->place()
            ->assertRedirect(self::COPPERS.'/carrito')
            ->assertSessionHas('cart_notices', ['Quitamos el cupón VERANO: Venció.']);

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(5, $variant->fresh()->stock);
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_the_last_use_of_a_coupon_can_only_be_taken_once()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5], ['price' => 500]));
        $coupon = Coupon::create(['store_id' => $this->coppers->id, 'code' => 'UNICO', 'type' => CouponType::Percent, 'value' => 10, 'max_uses' => 1]);
        $luis = Customer::factory()->create();

        $this->addToCart($variant);
        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'UNICO']);
        $saved = [session('shop.cart.'.$this->coppers->id), session(CouponService::SESSION_KEY)];

        $this->place()->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');

        // Luis traía el mismo cupón aplicado en su sesión.
        session()->put('shop.cart.'.$this->coppers->id, $saved[0]);
        session()->put(CouponService::SESSION_KEY, $saved[1]);

        $this->place(customer: $luis)
            ->assertRedirect(self::COPPERS.'/carrito')
            ->assertSessionHas('cart_notices', ['Quitamos el cupón UNICO: Ya no tiene usos.']);

        $this->assertSame(1, Order::withoutGlobalScopes()->count());
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame(4, $variant->fresh()->stock);
    }

    public function test_an_order_without_coupon_has_no_discount()
    {
        $this->addToCart($this->variant($this->product($this->coppers, 'Camisa')));
        $this->place();

        $order = Order::withoutGlobalScopes()->sole();

        $this->assertNull($order->coupon_id);
        $this->assertSame('0.00', $order->discount);
    }

    // ---- Número de pedido ----------------------------------------------------------------------

    public function test_order_numbers_are_consecutive_per_store_with_the_stores_prefix()
    {
        $shirt = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 9]));
        $shoes = $this->variant($this->product($this->g5, 'Tenis', ['27' => 9]));

        $this->addToCart($shirt);
        $this->place()->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');

        $this->addToCart($shoes, host: self::G5);
        $this->place(host: self::G5)->assertRedirect(self::G5.'/pedido/G5-00001/gracias');

        $this->addToCart($shirt);
        $this->place()->assertRedirect(self::COPPERS.'/pedido/CP-00002/gracias');

        $this->assertSame(['CP-00001', 'G5-00001', 'CP-00002'], Order::withoutGlobalScopes()->orderBy('id')->pluck('number')->all());
    }

    public function test_a_store_without_a_configured_prefix_uses_its_slug()
    {
        $other = Store::create(['name' => 'Otra', 'slug' => 'otra-tienda', 'domain' => 'otra.localhost', 'theme' => 'coppers']);
        $this->addToCart($this->variant($this->product($other, 'Gorra')), host: 'http://otra.localhost');

        $this->place(host: 'http://otra.localhost')->assertRedirect('http://otra.localhost/pedido/OTRATIENDA-00001/gracias');
    }

    // ---- Entrega, dirección y contacto --------------------------------------------------------

    public function test_the_checkout_page_offers_only_pickup_and_local_delivery()
    {
        $this->addToCart($this->variant($this->product($this->coppers, 'Camisa', ['M' => 5], ['price' => 500])), 2);
        $this->addToCart($this->variant($this->product($this->g5, 'Tenis', ['27' => 5])), host: self::G5);
        $this->ana->update(['phone' => '7551112233']);

        // Copper's cobra 80 la entrega local (y tiene costo nacional guardado, que ya no se ofrece).
        $this->actingAs($this->ana, 'customer')->get(self::COPPERS.'/checkout')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('checkout')
                ->where('cart.subtotal', '1000.00')
                ->where('cart.discount', '0.00')
                ->has('cart.lines', 1)
                ->where('deliveryOptions', [
                    ['value' => 'recoger', 'label' => 'Recoger en tienda', 'description' => 'Pasas por tu pedido a la tienda.', 'cost' => '0.00'],
                    ['value' => 'local', 'label' => 'Entrega local (Zihuatanejo)', 'description' => 'Te lo llevamos al punto que nos digas en Zihuatanejo.', 'cost' => '80.00'],
                ])
                ->where('customerName', 'Ana Pérez')
                ->where('phone', '7551112233')
                ->where('reserveHours', 48)
                ->missing('addresses'));

        // G5 no tiene ningún costo configurado: salen las mismas dos, la local gratis.
        $this->actingAs($this->ana, 'customer')->get(self::G5.'/checkout')
            ->assertInertia(fn (Assert $page) => $page
                ->has('deliveryOptions', 2)
                ->where('deliveryOptions.0.value', 'recoger')
                ->where('deliveryOptions.1.value', 'local')
                ->where('deliveryOptions.1.cost', '0.00'));

        // Ni con costo nacional en las dos tiendas aparece el envío nacional o un «Próximamente».
        $this->g5->update(['national_shipping_cost' => 300]);
        foreach ([self::COPPERS, self::G5] as $host) {
            $this->get("{$host}/checkout")->assertInertia(fn (Assert $page) => $page
                ->where('deliveryOptions', fn ($options) => collect($options)->pluck('value')->all() === ['recoger', 'local']
                    && ! str_contains(json_encode($options, JSON_UNESCAPED_UNICODE), 'nacional')
                    && ! str_contains(json_encode($options, JSON_UNESCAPED_UNICODE), 'Próximamente')));
        }
    }

    public function test_local_delivery_is_always_available_and_free_without_a_configured_cost()
    {
        $this->assertNull($this->g5->local_shipping_cost);
        $this->addToCart($this->variant($this->product($this->g5, 'Tenis', ['27' => 5], ['price' => 900])), host: self::G5);

        $this->place($this->local(), host: self::G5)->assertRedirect(self::G5.'/pedido/G5-00001/gracias');

        $order = Order::withoutGlobalScopes()->sole();

        $this->assertSame(DeliveryMethod::Local, $order->delivery_method);
        $this->assertSame(['900.00', '0.00', '900.00'], [$order->subtotal, $order->shipping_cost, $order->total]);

        // Con 0 capturado también es gratis.
        $this->g5->update(['local_shipping_cost' => 0]);
        $this->addToCart($this->variant($this->product($this->g5, 'Balón', ['5' => 5], ['price' => 300])), host: self::G5);
        $this->place($this->local(), host: self::G5)->assertSessionHasNoErrors();

        $second = Order::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(['0.00', '300.00'], [$second->shipping_cost, $second->total]);
    }

    public function test_local_delivery_with_a_cost_adds_it_to_the_total_from_the_database()
    {
        $this->g5->update(['local_shipping_cost' => 45.5]);
        $this->addToCart($this->variant($this->product($this->coppers, 'Camisa', ['M' => 5], ['price' => 500])));
        $this->addToCart($this->variant($this->product($this->g5, 'Tenis', ['27' => 5], ['price' => 900])), host: self::G5);

        // El navegador manda otro costo en las dos: gana siempre el de cada tienda.
        $this->place([...$this->local(), 'shipping_cost' => 1, 'cost' => 1, 'total' => 1]);
        $this->place([...$this->local(), 'shipping_cost' => 1, 'cost' => 1, 'total' => 1], host: self::G5);

        $coppers = Order::withoutGlobalScopes()->where('store_id', $this->coppers->id)->sole();
        $g5 = Order::withoutGlobalScopes()->where('store_id', $this->g5->id)->sole();

        $this->assertSame(['80.00', '580.00'], [$coppers->shipping_cost, $coppers->total]);
        $this->assertSame(['45.50', '945.50'], [$g5->shipping_cost, $g5->total]);
    }

    public function test_national_shipping_is_rejected_by_the_server_even_if_the_store_has_a_cost()
    {
        // Copper's tiene costo nacional guardado de antes: no importa, ya no se acepta.
        $this->assertSame('250.00', $this->coppers->fresh()->national_shipping_cost);
        $variant = $this->variant($this->product($this->coppers, 'Camisa'));
        $this->addToCart($variant);

        foreach (['nacional', 'dron', ''] as $method) {
            $this->place([
                'delivery_method' => $method,
                'address' => $this->addressData(),
                'local' => $this->local()['local'],
                'available' => true, 'shipping_cost' => 0,
            ])->assertSessionHasErrors('delivery_method');
        }

        $this->place(['delivery_method' => 'nacional', 'address' => $this->addressData()])
            ->assertSessionHasErrors(['delivery_method' => 'Ese método de entrega no está disponible en esta tienda.']);

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, Address::count());
        $this->assertSame(5, $variant->fresh()->stock);

        // El valor del enum y los pedidos nacionales de antes siguen existiendo.
        $this->assertSame('nacional', DeliveryMethod::National->value);
    }

    public function test_pickup_needs_no_address_and_is_free()
    {
        $this->addToCart($this->variant($this->product($this->coppers, 'Camisa')));

        // Aunque mande datos de entrega local, al recoger no se guardan.
        $this->place(['local' => ['name' => 'Otro', 'point' => 'Otro lado']]);

        $order = Order::withoutGlobalScopes()->sole();

        $this->assertSame(DeliveryMethod::Pickup, $order->delivery_method);
        $this->assertNull($order->shipping_address);
        $this->assertSame('0.00', $order->shipping_cost);
    }

    public function test_local_delivery_saves_the_point_and_leaves_the_date_for_the_admin()
    {
        $this->travelTo('2026-10-05 10:30:00');
        $this->addToCart($this->variant($this->product($this->coppers, 'Camisa')));

        $this->place($this->local([
            'name' => ' Luisa Pérez ', 'point' => ' Hotel Sol, lobby ', 'references' => ' Frente a la playa ',
        ]))->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');

        $order = Order::withoutGlobalScopes()->sole();

        $this->assertSame([
            'name' => 'Luisa Pérez', 'phone' => '755 123 4567', 'street' => 'Hotel Sol, lobby', 'city' => 'Zihuatanejo', 'notes' => 'Frente a la playa',
        ], $order->shipping_address);

        // No se le pide día ni hora: la fecha la acuerda y captura el admin después.
        $this->assertNull($order->delivery_scheduled_date);
        $this->assertNull($order->delivery_window);

        // No se guarda en la libreta de direcciones.
        $this->assertSame(0, Address::count());

        // La página de gracias lo muestra, sin ninguna preferencia de día u hora.
        $this->actingAs($this->ana, 'customer')->get(self::COPPERS.'/pedido/CP-00001/gracias')
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.shipping_address.street', 'Hotel Sol, lobby')
                ->missing('order.delivery_preference')
                ->where('pickup', null));
    }

    public function test_the_checkout_does_not_take_a_day_or_time_for_local_delivery()
    {
        $this->addToCart($this->variant($this->product($this->coppers, 'Camisa')));

        // Aunque lo manden a mano, ni se valida ni se guarda.
        $this->place($this->local(['preferred_date' => '2026-10-04', 'preferred_window' => 'Tarde']))->assertSessionHasNoErrors();

        $order = Order::withoutGlobalScopes()->sole();

        $this->assertNull($order->delivery_scheduled_date);
        $this->assertNull($order->delivery_window);
        $this->assertSame('Portón negro', $order->shipping_address['notes']);
        $this->assertStringNotContainsString('prefer', json_encode($order->getAttributes()));
    }

    public function test_local_delivery_requires_who_receives_and_the_delivery_point()
    {
        $this->travelTo('2026-10-05 10:30:00');
        $variant = $this->variant($this->product($this->coppers, 'Camisa'));
        $this->addToCart($variant);

        $this->place(['delivery_method' => 'local'])->assertSessionHasErrors('local');
        $this->place(['delivery_method' => 'local', 'local' => ['name' => '', 'point' => '']])
            ->assertSessionHasErrors(['local.name', 'local.point' => 'Escribe el punto de entrega o la dirección en Zihuatanejo.']);
        $this->place(['delivery_method' => 'local', 'local' => ['name' => 'Ana', 'point' => '   ']])
            ->assertSessionHasErrors('local.point');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(5, $variant->fresh()->stock);

        // Con los dos datos completos sí se aparta.
        $this->place($this->local())->assertSessionHasNoErrors();
        $this->assertSame(1, Order::withoutGlobalScopes()->count());
    }

    // ---- Recoger en tienda: datos de SU tienda ----------------------------------------------------

    public function test_the_pickup_box_uses_the_data_of_the_current_store()
    {
        $this->coppers->update([
            'pickup_address' => 'Av. Juan Álvarez 12, Centro, Zihuatanejo',
            'pickup_hours' => 'Lun a Sáb 10:00 a 19:00',
            'pickup_map_url' => 'https://maps.app.goo.gl/coppers',
            'pickup_notes' => 'Local 4, junto a la farmacia',
        ]);
        $this->addToCart($this->variant($this->product($this->coppers, 'Camisa')));
        $this->addToCart($this->variant($this->product($this->g5, 'Tenis', ['27' => 5])), host: self::G5);

        $coppers = [
            'configured' => true,
            'address' => 'Av. Juan Álvarez 12, Centro, Zihuatanejo',
            'hours' => 'Lun a Sáb 10:00 a 19:00',
            'map_url' => 'https://maps.app.goo.gl/coppers',
            'notes' => 'Local 4, junto a la farmacia',
        ];
        $empty = ['configured' => false, 'address' => null, 'hours' => null, 'map_url' => null, 'notes' => null];

        $this->actingAs($this->ana, 'customer');
        $this->get(self::COPPERS.'/checkout')->assertInertia(fn (Assert $page) => $page->where('pickup', $coppers));
        // G5 todavía no captura nada: la pantalla dice que se confirma por WhatsApp.
        $this->get(self::G5.'/checkout')->assertInertia(fn (Assert $page) => $page->where('pickup', $empty));

        // Los mismos datos salen en la página de gracias y en el detalle del pedido, cada quien los de su tienda.
        $this->place()->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');
        $this->place(host: self::G5)->assertRedirect(self::G5.'/pedido/G5-00001/gracias');

        $this->get(self::COPPERS.'/pedido/CP-00001/gracias')->assertInertia(fn (Assert $page) => $page->where('pickup', $coppers));
        $this->get(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')->assertInertia(fn (Assert $page) => $page->where('pickup', $coppers));
        $this->get(self::G5.'/pedido/G5-00001/gracias')->assertInertia(fn (Assert $page) => $page->where('pickup', $empty));
        $this->get(self::G5.'/mi-cuenta/pedidos/G5-00001')->assertInertia(fn (Assert $page) => $page->where('pickup', $empty));

        // Un pedido de entrega local no lleva el recuadro de recoger.
        $this->addToCart($this->variant($this->product($this->coppers, 'Pantalón')));
        $this->place($this->local());
        $this->get(self::COPPERS.'/pedido/CP-00002/gracias')->assertInertia(fn (Assert $page) => $page->where('pickup', null));
        $this->get(self::COPPERS.'/mi-cuenta/pedidos/CP-00002')->assertInertia(fn (Assert $page) => $page
            ->where('pickup', null)
            ->where('order.shipping_address.street', 'Calle Hidalgo 5, Centro'));
    }

    public function test_with_only_the_hours_captured_the_pickup_box_is_configured()
    {
        $this->g5->update(['pickup_hours' => '  Todos los días 9 a 9  ']);

        $this->assertSame(
            ['configured' => true, 'address' => null, 'hours' => 'Todos los días 9 a 9', 'map_url' => null, 'notes' => null],
            $this->g5->pickupInfo(),
        );
    }

    public function test_the_contact_phone_is_required_and_saved_on_the_customer()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa'));
        $this->addToCart($variant);

        $this->place(['phone' => ''])->assertSessionHasErrors('phone');
        $this->place(['phone' => '12345'])->assertSessionHasErrors(['phone' => 'Escribe un teléfono válido, por ejemplo 755 123 4567.']);
        $this->assertSame(0, Order::withoutGlobalScopes()->count());

        $this->place(['phone' => ' 755 987 6543 '])->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');

        $this->assertSame('755 987 6543', $this->ana->fresh()->phone);
    }

    // ---- Acceso ---------------------------------------------------------------------------------

    public function test_checkout_needs_a_customer_session()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa'));
        $this->addToCart($variant);

        $this->get(self::COPPERS.'/checkout')->assertRedirect(self::COPPERS.'/login');
        $this->post(self::COPPERS.'/checkout', ['delivery_method' => 'recoger', 'phone' => '7551234567'])->assertRedirect(self::COPPERS.'/login');

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($owner, 'web')->post(self::COPPERS.'/checkout', ['delivery_method' => 'recoger', 'phone' => '7551234567'])->assertRedirect(self::COPPERS.'/login');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(5, $variant->fresh()->stock);
    }

    public function test_checkout_with_an_empty_cart_goes_back_to_the_cart()
    {
        $this->actingAs($this->ana, 'customer')->get(self::COPPERS.'/checkout')->assertRedirect(self::COPPERS.'/carrito');

        $this->place()
            ->assertRedirect(self::COPPERS.'/carrito')
            ->assertSessionHas('cart_notices', ['Tu carrito está vacío.']);

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_the_checkout_page_sends_back_to_the_cart_when_something_changed()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 3]));
        $this->addToCart($variant, 3);
        $variant->update(['stock' => 2]);

        $this->actingAs($this->ana, 'customer')->get(self::COPPERS.'/checkout')
            ->assertRedirect(self::COPPERS.'/carrito')
            ->assertSessionHas('cart_notices', ['Solo quedan 2 piezas de Camisa (talla M): ajustamos la cantidad.']);
    }

    public function test_submitting_twice_does_not_create_two_orders()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa'));
        $this->addToCart($variant);

        $this->place()->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');
        $this->place()->assertRedirect(self::COPPERS.'/carrito');

        $this->assertSame(1, Order::withoutGlobalScopes()->count());
        $this->assertSame(4, $variant->fresh()->stock);
    }

    public function test_an_order_in_one_store_does_not_touch_the_other_stores_cart()
    {
        $shirt = $this->variant($this->product($this->coppers, 'Camisa'));
        $shoes = $this->variant($this->product($this->g5, 'Tenis', ['27' => 5]));
        $this->addToCart($shirt);
        $this->addToCart($shoes, host: self::G5);

        $this->place();

        $this->assertSame([$shoes->id => 1], session('shop.cart.'.$this->g5->id));
        $this->assertSame(5, $shoes->fresh()->stock);
    }

    // ---- Panel ---------------------------------------------------------------------------------

    private function asPanelUser(User $user, Store $store): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($store);
    }

    public function test_the_owner_sets_the_pickup_data_and_the_optional_local_cost_in_store_profile()
    {
        $this->asPanelUser(User::factory()->create(['role' => UserRole::Owner]), $this->g5);

        Livewire::test(EditStoreProfile::class)
            ->assertSee('Recoger en tienda')
            ->assertSee('Dirección de la tienda')
            ->assertSee('Horario para recoger')
            ->assertSee('Enlace de Google Maps')
            ->assertSee('Indicaciones')
            ->assertSee('Costo de entrega local')
            // Ya no hay interruptores ni nada del envío a todo el estado.
            ->assertDontSee('Ofrecer entrega a domicilio')
            ->assertDontSee('Ofrecer envíos a todo el estado')
            ->assertDontSee('Costo de envío nacional')
            ->assertDontSee('Próximamente')
            ->fillForm([
                'name' => 'Sports G5',
                'pickup_address' => 'Calle Cuauhtémoc 8, Centro',
                'pickup_hours' => 'Lun a Sáb 10:00 a 19:00',
                'pickup_map_url' => 'https://maps.app.goo.gl/g5',
                'pickup_notes' => 'Segundo piso',
                'local_shipping_cost' => 60,
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->g5->refresh();

        $this->assertSame(
            ['Calle Cuauhtémoc 8, Centro', 'Lun a Sáb 10:00 a 19:00', 'https://maps.app.goo.gl/g5', 'Segundo piso', '60.00'],
            [$this->g5->pickup_address, $this->g5->pickup_hours, $this->g5->pickup_map_url, $this->g5->pickup_notes, $this->g5->local_shipping_cost],
        );

        // El costo local es opcional: vacío se guarda como null y la entrega sigue ofreciéndose, gratis.
        Livewire::test(EditStoreProfile::class)
            ->fillForm(['local_shipping_cost' => null, 'pickup_map_url' => null, 'pickup_notes' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull($this->g5->refresh()->local_shipping_cost);
        $this->assertNull($this->g5->pickup_map_url);
        $this->assertSame(['recoger' => 0, 'local' => 0], app(OrderService::class)->deliveryOptions($this->g5));
    }

    public function test_saving_the_profile_does_not_touch_the_stored_national_cost_or_the_other_store()
    {
        $this->asPanelUser(User::factory()->create(['role' => UserRole::Owner]), $this->coppers);

        Livewire::test(EditStoreProfile::class)
            ->fillForm(['local_shipping_cost' => 95])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->coppers->refresh();

        $this->assertSame('95.00', $this->coppers->local_shipping_cost);
        // La columna y su valor se conservan, aunque ya no se ofrezca.
        $this->assertSame('250.00', $this->coppers->national_shipping_cost);
        $this->assertNull($this->g5->fresh()->local_shipping_cost);
    }

    public function test_the_local_cost_must_be_a_number_from_zero_with_two_decimals_and_the_map_a_link()
    {
        $this->asPanelUser(User::factory()->create(['role' => UserRole::Owner]), $this->g5);

        foreach ([-5, 'gratis', 10.999] as $bad) {
            Livewire::test(EditStoreProfile::class)
                ->fillForm(['local_shipping_cost' => $bad])
                ->call('save')
                ->assertHasFormErrors(['local_shipping_cost']);
        }

        Livewire::test(EditStoreProfile::class)
            ->fillForm(['pickup_map_url' => 'por la farmacia'])
            ->call('save')
            ->assertHasFormErrors(['pickup_map_url']);

        $this->assertNull($this->g5->fresh()->local_shipping_cost);
        $this->assertNull($this->g5->fresh()->pickup_map_url);
    }

    public function test_only_the_owner_edits_the_shipping_settings()
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $staff->stores()->attach($this->g5);
        $this->asPanelUser($staff, $this->g5);

        $this->assertFalse(EditStoreProfile::canView($this->g5));
        Livewire::test(EditStoreProfile::class)->assertNotFound();
        $this->get('http://localhost/admin/'.$this->g5->getRouteKey().'/profile')->assertNotFound();

        $this->assertNull($this->g5->fresh()->local_shipping_cost);
    }
}
