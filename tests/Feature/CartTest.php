<?php

namespace Tests\Feature;

use App\Enums\CouponType;
use App\Enums\SizeType;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private const COPPERS = 'http://coppers.localhost';

    private const G5 = 'http://g5.localhost';

    private Store $coppers;

    private Store $g5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);
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
            'price' => 100,
            ...$extra,
        ]);

        foreach ($sizes as $size => $stock) {
            $product->variants()->create(['size' => (string) $size, 'stock' => $stock]);
        }

        return $product;
    }

    private function variant(Product $product, ?string $size = null): ProductVariant
    {
        return $size === null
            ? $product->variants()->orderBy('id')->firstOrFail()
            : $product->variants()->where('size', $size)->firstOrFail();
    }

    private function coupon(Store $store, string $code, array $extra = []): Coupon
    {
        return Coupon::create(['store_id' => $store->id, 'code' => $code, 'type' => CouponType::Percent, 'value' => 10, ...$extra]);
    }

    private function add(string $host, ProductVariant $variant, int $quantity = 1)
    {
        return $this->post("{$host}/carrito", ['variant_id' => $variant->id, 'quantity' => $quantity]);
    }

    /** Props de la página /carrito. */
    private function cart(string $host): array
    {
        $props = [];

        $this->get("{$host}/carrito")->assertOk()->assertInertia(function (Assert $page) use (&$props) {
            $page->component('cart');
            $props = $page->toArray()['props'];
        });

        return $props;
    }

    private function sessionCart(Store $store): array
    {
        return session('shop.cart.'.$store->id, []);
    }

    // ---- Stock --------------------------------------------------------------------------------

    public function test_a_size_without_backorder_can_not_go_above_its_stock()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 3]));

        $this->add(self::COPPERS, $variant, 3)->assertSessionHasNoErrors();
        $this->assertSame([$variant->id => 3], $this->sessionCart($this->coppers));

        // Una pieza más pasa del stock.
        $this->add(self::COPPERS, $variant, 1)
            ->assertSessionHasErrors(['cart' => 'Ya tienes 3 en tu carrito y solo quedan 3 piezas en talla M.']);
        $this->assertSame([$variant->id => 3], $this->sessionCart($this->coppers));

        // Cambiar la cantidad a más del stock también.
        $this->patch(self::COPPERS.'/carrito/'.$variant->id, ['quantity' => 4])
            ->assertSessionHasErrors(['cart' => 'Solo quedan 3 piezas en talla M.']);
        $this->assertSame([$variant->id => 3], $this->sessionCart($this->coppers));
    }

    public function test_a_first_add_above_the_stock_is_rejected_with_the_pieces_left()
    {
        $three = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 3]));
        $one = $this->variant($this->product($this->coppers, 'Pantalón', ['M' => 1]));

        $this->add(self::COPPERS, $three, 4)->assertSessionHasErrors(['cart' => 'Solo quedan 3 piezas en talla M.']);
        $this->add(self::COPPERS, $one, 2)->assertSessionHasErrors(['cart' => 'Solo queda 1 pieza en talla M.']);

        $this->assertSame([], $this->sessionCart($this->coppers));
    }

    public function test_a_sold_out_size_without_backorder_can_not_be_added()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 0]));

        $this->add(self::COPPERS, $variant)->assertSessionHasErrors(['cart' => 'La talla M está agotada.']);

        $this->assertSame([], $this->sessionCart($this->coppers));
    }

    public function test_a_backorder_product_can_go_above_its_stock_and_the_line_is_marked()
    {
        $product = $this->product($this->coppers, 'Camisa', ['M' => 1], ['allow_backorder' => true, 'backorder_days' => 7]);
        $variant = $this->variant($product);

        $this->add(self::COPPERS, $variant, 4)->assertSessionHasNoErrors();

        $line = $this->cart(self::COPPERS)['cart']['lines'][0];

        $this->assertSame(4, $line['quantity']);
        $this->assertSame(3, $line['backorder_quantity']);
        $this->assertSame(7, $line['backorder_days']);
        $this->assertSame(10, $line['max_quantity']);
    }

    public function test_a_line_inside_the_stock_is_not_marked_as_backorder_even_if_the_product_allows_it()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5], ['allow_backorder' => true, 'backorder_days' => 7]));

        $this->add(self::COPPERS, $variant, 5);

        $line = $this->cart(self::COPPERS)['cart']['lines'][0];

        $this->assertSame(0, $line['backorder_quantity']);
        $this->assertNull($line['backorder_days']);
    }

    public function test_a_sold_out_size_with_backorder_can_be_added()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 0], ['allow_backorder' => true, 'backorder_days' => 5]));

        $this->add(self::COPPERS, $variant, 2)->assertSessionHasNoErrors();

        $line = $this->cart(self::COPPERS)['cart']['lines'][0];

        $this->assertSame(2, $line['backorder_quantity']);
    }

    public function test_there_is_a_maximum_per_line_even_with_backorder()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 0], ['allow_backorder' => true]));

        $this->add(self::COPPERS, $variant, 10)->assertSessionHasNoErrors();
        $this->add(self::COPPERS, $variant, 1)->assertSessionHasErrors(['cart' => 'Puedes llevar máximo 10 piezas de cada producto.']);
        $this->patch(self::COPPERS.'/carrito/'.$variant->id, ['quantity' => 11])->assertSessionHasErrors('cart');

        $this->assertSame([$variant->id => 10], $this->sessionCart($this->coppers));
    }

    public function test_adding_the_same_size_again_adds_up()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5]));

        $this->add(self::COPPERS, $variant, 1);
        $this->add(self::COPPERS, $variant, 2);

        $this->assertSame([$variant->id => 3], $this->sessionCart($this->coppers));
    }

    // ---- Qué se puede agregar ----------------------------------------------------------------

    public function test_a_variant_from_the_other_store_is_rejected()
    {
        $theirs = $this->variant($this->product($this->g5, 'Tenis', ['M' => 5]));

        $this->add(self::COPPERS, $theirs)->assertSessionHasErrors(['cart' => 'Este producto no está disponible.']);
        $this->assertSame([], $this->sessionCart($this->coppers));
        $this->assertSame([], $this->sessionCart($this->g5));

        // Y en su propia tienda sí se puede.
        $this->add(self::G5, $theirs)->assertSessionHasNoErrors();
        $this->assertSame([$theirs->id => 1], $this->sessionCart($this->g5));
    }

    public function test_inactive_deleted_and_unknown_variants_are_rejected()
    {
        $inactive = $this->variant($this->product($this->coppers, 'Apagado', ['M' => 5], ['is_active' => false]));
        $deletedVariant = $this->variant($this->product($this->coppers, 'Talla borrada', ['M' => 5]));
        $deletedVariant->delete();
        $deletedProduct = $this->product($this->coppers, 'Producto borrado', ['M' => 5]);
        $deletedProductVariant = $this->variant($deletedProduct);
        $deletedProduct->delete();

        foreach ([$inactive->id, $deletedVariant->id, $deletedProductVariant->id, 999999] as $id) {
            $this->post(self::COPPERS.'/carrito', ['variant_id' => $id])
                ->assertSessionHasErrors(['cart' => 'Este producto no está disponible.']);
        }

        $this->assertSame([], $this->sessionCart($this->coppers));
    }

    public function test_the_request_is_validated()
    {
        $this->post(self::COPPERS.'/carrito', [])->assertSessionHasErrors('variant_id');
        $this->post(self::COPPERS.'/carrito', ['variant_id' => 'abc'])->assertSessionHasErrors('variant_id');

        $variant = $this->variant($this->product($this->coppers, 'Camisa'));

        $this->post(self::COPPERS.'/carrito', ['variant_id' => $variant->id, 'quantity' => 0])->assertSessionHasErrors('quantity');
        $this->post(self::COPPERS.'/carrito', ['variant_id' => $variant->id, 'quantity' => -3])->assertSessionHasErrors('quantity');
        $this->post(self::COPPERS.'/carrito', ['variant_id' => $variant->id, 'quantity' => 'dos'])->assertSessionHasErrors('quantity');

        $this->assertSame([], $this->sessionCart($this->coppers));
    }

    // ---- El precio sale de la base de datos ---------------------------------------------------

    public function test_the_price_comes_from_the_database_even_if_the_request_is_tampered_with()
    {
        $product = $this->product($this->coppers, 'Camisa Oxford', ['M' => 5], ['price' => 1700]);
        $variant = $this->variant($product);

        $this->post(self::COPPERS.'/carrito', [
            'variant_id' => $variant->id,
            'quantity' => 2,
            'price' => 1,
            'unit_price' => 1,
            'line_total' => 2,
            'subtotal' => 2,
            'total' => 2,
            'name' => 'Regalo',
            'product_id' => 999,
        ])->assertSessionHasNoErrors();

        $cart = $this->cart(self::COPPERS)['cart'];

        $this->assertSame('1700.00', $cart['lines'][0]['unit_price']);
        $this->assertSame('3400.00', $cart['lines'][0]['line_total']);
        $this->assertSame('Camisa Oxford', $cart['lines'][0]['name']);
        $this->assertSame('3400.00', $cart['subtotal']);
        $this->assertSame('3400.00', $cart['total']);

        // Tampoco al cambiar la cantidad.
        $this->patch(self::COPPERS.'/carrito/'.$variant->id, ['quantity' => 3, 'price' => 1, 'unit_price' => 1]);

        $this->assertSame('5100.00', $this->cart(self::COPPERS)['cart']['subtotal']);
    }

    public function test_the_session_keeps_only_variant_and_quantity()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5], ['price' => 1700]));

        $this->post(self::COPPERS.'/carrito', ['variant_id' => $variant->id, 'quantity' => 2, 'price' => 1, 'name' => 'Regalo']);

        $this->assertSame([$variant->id => 2], $this->sessionCart($this->coppers));
    }

    public function test_the_cart_always_uses_the_current_database_price_and_warns_about_a_change()
    {
        $product = $this->product($this->coppers, 'Camisa Oxford', ['M' => 5], ['price' => 1000]);
        $variant = $this->variant($product);
        $this->add(self::COPPERS, $variant, 2);

        $product->update(['price' => 1250]);

        $props = $this->cart(self::COPPERS);

        $this->assertSame('1250.00', $props['cart']['lines'][0]['unit_price']);
        $this->assertSame('2500.00', $props['cart']['subtotal']);
        $this->assertSame(['El precio de Camisa Oxford (talla M) cambió de $1,000.00 a $1,250.00.'], $props['notices']);

        // Ya está avisado y corregido: la siguiente carga no repite el aviso.
        $this->assertSame([], $this->cart(self::COPPERS)['notices']);
    }

    // ---- Una tienda no ve el carrito de la otra ----------------------------------------------

    public function test_the_carts_of_coppers_and_g5_are_independent()
    {
        $shirt = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5], ['price' => 500]));
        $shoes = $this->variant($this->product($this->g5, 'Tenis', ['27' => 5], ['price' => 1800]));

        $this->add(self::COPPERS, $shirt, 2);
        $this->add(self::G5, $shoes, 1);

        $coppers = $this->cart(self::COPPERS)['cart'];
        $g5 = $this->cart(self::G5)['cart'];

        $this->assertSame(['Camisa'], array_column($coppers['lines'], 'name'));
        $this->assertSame(2, $coppers['count']);
        $this->assertSame('1000.00', $coppers['subtotal']);

        $this->assertSame(['Tenis'], array_column($g5['lines'], 'name'));
        $this->assertSame(1, $g5['count']);
        $this->assertSame('1800.00', $g5['subtotal']);

        // Vaciar uno no toca al otro.
        $this->delete(self::COPPERS.'/carrito')->assertRedirect(self::COPPERS.'/carrito');

        $this->assertSame([], $this->cart(self::COPPERS)['cart']['lines']);
        $this->assertSame(['Tenis'], array_column($this->cart(self::G5)['cart']['lines'], 'name'));
    }

    public function test_a_store_never_shows_lines_from_the_other_stores_cart()
    {
        $shirt = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5]));
        $this->add(self::COPPERS, $shirt);

        // Aunque alguien meta una talla de Copper's en el carrito de G5, G5 no la muestra ni la cobra.
        session()->put('shop.cart.'.$this->g5->id, [$shirt->id => 1]);

        $props = $this->cart(self::G5);

        $this->assertSame([], $props['cart']['lines']);
        $this->assertSame('0.00', $props['cart']['subtotal']);
        $this->assertSame(['Un producto de tu carrito ya no está disponible y lo quitamos.'], $props['notices']);
        $this->assertSame([], $this->sessionCart($this->g5));
    }

    public function test_the_header_counter_and_mini_cart_follow_each_stores_cart()
    {
        $shirt = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5], ['price' => 500]));
        $this->add(self::COPPERS, $shirt, 3);

        $this->get(self::COPPERS.'/')->assertInertia(fn (Assert $page) => $page
            ->where('miniCart.count', 3)
            ->where('miniCart.subtotal', '1500.00')
            ->where('miniCart.lines.0.name', 'Camisa'));

        $this->get(self::G5.'/')->assertInertia(fn (Assert $page) => $page
            ->where('miniCart.count', 0)
            ->where('miniCart.lines', []));
    }

    // ---- Cantidades, quitar y vaciar ---------------------------------------------------------

    public function test_the_quantity_can_be_changed_and_a_zero_removes_the_line()
    {
        $shirt = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5]));
        $pants = $this->variant($this->product($this->coppers, 'Pantalón', ['M' => 5]));
        $this->add(self::COPPERS, $shirt);
        $this->add(self::COPPERS, $pants);

        $this->patch(self::COPPERS.'/carrito/'.$shirt->id, ['quantity' => 4])->assertRedirect(self::COPPERS.'/carrito');
        $this->assertSame([$shirt->id => 4, $pants->id => 1], $this->sessionCart($this->coppers));

        $this->patch(self::COPPERS.'/carrito/'.$shirt->id, ['quantity' => 0]);
        $this->assertSame([$pants->id => 1], $this->sessionCart($this->coppers));
    }

    public function test_a_line_can_be_removed()
    {
        $shirt = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5]));
        $this->add(self::COPPERS, $shirt);

        $this->delete(self::COPPERS.'/carrito/'.$shirt->id)->assertRedirect(self::COPPERS.'/carrito');

        $this->assertSame([], $this->sessionCart($this->coppers));
    }

    public function test_changing_the_quantity_of_something_not_in_the_cart_is_rejected()
    {
        $shirt = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5]));

        $this->patch(self::COPPERS.'/carrito/'.$shirt->id, ['quantity' => 2])
            ->assertSessionHasErrors(['cart' => 'Ese producto no está en tu carrito.']);

        $this->assertSame([], $this->sessionCart($this->coppers));
    }

    public function test_the_cart_page_of_an_empty_cart()
    {
        $props = $this->cart(self::COPPERS);

        $this->assertSame([], $props['cart']['lines']);
        $this->assertSame(0, $props['cart']['count']);
        $this->assertSame('0.00', $props['cart']['subtotal']);
        $this->assertSame('0.00', $props['cart']['total']);
        $this->assertNull($props['cart']['coupon']);
        $this->assertSame([], $props['notices']);
    }

    public function test_the_cart_lines_carry_photo_name_size_and_price()
    {
        $product = $this->product($this->coppers, 'Camisa Oxford', ['M' => 5], ['price' => 1700, 'color' => 'Azul']);
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/segunda.jpg', 'position' => 1]);
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/primera.jpg', 'position' => 0]);
        $this->add(self::COPPERS, $this->variant($product));

        $line = $this->cart(self::COPPERS)['cart']['lines'][0];

        $this->assertSame('/storage/products/primera.jpg', $line['image_url']);
        $this->assertSame('Camisa Oxford', $line['name']);
        $this->assertSame('Azul', $line['color']);
        $this->assertSame('M', $line['size']);
        $this->assertSame('camisa-oxford', $line['slug']);
        $this->assertSame('1700.00', $line['unit_price']);
    }

    // ---- Lo que cambia desde que se agregó ----------------------------------------------------

    public function test_a_size_that_sold_out_is_removed_with_a_notice()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 3]));
        $this->add(self::COPPERS, $variant, 2);

        $variant->update(['stock' => 0]);

        $props = $this->cart(self::COPPERS);

        $this->assertSame([], $props['cart']['lines']);
        $this->assertSame(['Camisa (talla M) se agotó y lo quitamos de tu carrito.'], $props['notices']);
        $this->assertSame([], $this->sessionCart($this->coppers));
    }

    public function test_a_quantity_above_the_new_stock_is_lowered_with_a_notice()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5], ['price' => 100]));
        $this->add(self::COPPERS, $variant, 4);

        $variant->update(['stock' => 1]);

        $props = $this->cart(self::COPPERS);

        $this->assertSame(1, $props['cart']['lines'][0]['quantity']);
        $this->assertSame('100.00', $props['cart']['subtotal']);
        $this->assertSame(['Solo queda 1 pieza de Camisa (talla M): ajustamos la cantidad.'], $props['notices']);
        $this->assertSame([$variant->id => 1], $this->sessionCart($this->coppers));
        $this->assertSame([], $this->cart(self::COPPERS)['notices']);
    }

    public function test_a_product_that_was_turned_off_or_deleted_is_removed_with_a_notice()
    {
        $off = $this->product($this->coppers, 'Camisa', ['M' => 5]);
        $gone = $this->product($this->coppers, 'Pantalón', ['M' => 5]);
        $this->add(self::COPPERS, $this->variant($off));
        $this->add(self::COPPERS, $this->variant($gone));

        $off->update(['is_active' => false]);
        $gone->delete();

        $props = $this->cart(self::COPPERS);

        $this->assertSame([], $props['cart']['lines']);
        $this->assertEqualsCanonicalizing([
            'Camisa (talla M) ya no está disponible y lo quitamos de tu carrito.',
            'Pantalón (talla M) ya no está disponible y lo quitamos de tu carrito.',
        ], $props['notices']);
        $this->assertSame([], $this->sessionCart($this->coppers));
    }

    public function test_a_product_that_stopped_allowing_backorder_is_adjusted_to_its_stock()
    {
        $product = $this->product($this->coppers, 'Camisa', ['M' => 2], ['allow_backorder' => true]);
        $variant = $this->variant($product);
        $this->add(self::COPPERS, $variant, 6);

        $product->update(['allow_backorder' => false]);

        $props = $this->cart(self::COPPERS);

        $this->assertSame(2, $props['cart']['lines'][0]['quantity']);
        $this->assertSame(0, $props['cart']['lines'][0]['backorder_quantity']);
        $this->assertSame(['Solo quedan 2 piezas de Camisa (talla M): ajustamos la cantidad.'], $props['notices']);
    }

    public function test_the_header_counter_does_not_count_lines_that_are_no_longer_available()
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 3]));
        $this->add(self::COPPERS, $variant, 2);
        $variant->update(['stock' => 0]);

        $this->get(self::COPPERS.'/')->assertInertia(fn (Assert $page) => $page->where('miniCart.count', 0));
    }

    // ---- Cupones ------------------------------------------------------------------------------

    private function cartWithCoupon(): array
    {
        $variant = $this->variant($this->product($this->coppers, 'Camisa', ['M' => 5], ['price' => 500]));
        $this->add(self::COPPERS, $variant, 2);

        return [$variant, $this->coupon($this->coppers, 'VERANO', ['value' => 10])];
    }

    public function test_a_valid_coupon_lowers_the_total()
    {
        [, $coupon] = $this->cartWithCoupon();

        $this->post(self::COPPERS.'/carrito/cupon', ['code' => ' verano '])
            ->assertRedirect(self::COPPERS.'/carrito')
            ->assertSessionHas('status', 'Cupón VERANO aplicado.')
            ->assertSessionHasNoErrors();

        $cart = $this->cart(self::COPPERS)['cart'];

        $this->assertSame('1000.00', $cart['subtotal']);
        $this->assertSame('100.00', $cart['discount']);
        $this->assertSame('900.00', $cart['total']);
        $this->assertSame(['code' => 'VERANO', 'description' => '10 % de descuento'], $cart['coupon']);

        // used_count solo cambia al crear el pedido, no aquí.
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_a_fixed_coupon_is_described_in_pesos_and_never_leaves_a_negative_total()
    {
        $variant = $this->variant($this->product($this->coppers, 'Calcetas', ['M' => 5], ['price' => 80]));
        $this->add(self::COPPERS, $variant);
        $this->coupon($this->coppers, 'GRANDE', ['type' => CouponType::Fixed, 'value' => 500]);

        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'GRANDE']);

        $cart = $this->cart(self::COPPERS)['cart'];

        $this->assertSame('80.00', $cart['discount']);
        $this->assertSame('0.00', $cart['total']);
        $this->assertSame('$500.00 de descuento', $cart['coupon']['description']);
    }

    public function test_the_total_follows_the_quantity_with_the_coupon_applied()
    {
        [$variant] = $this->cartWithCoupon();
        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'VERANO']);

        $this->patch(self::COPPERS.'/carrito/'.$variant->id, ['quantity' => 4]);

        $cart = $this->cart(self::COPPERS)['cart'];

        $this->assertSame('2000.00', $cart['subtotal']);
        $this->assertSame('200.00', $cart['discount']);
        $this->assertSame('1800.00', $cart['total']);
    }

    public function test_a_coupon_applied_in_coppers_does_not_show_as_applied_in_g5()
    {
        $this->cartWithCoupon();
        $shoes = $this->variant($this->product($this->g5, 'Tenis', ['27' => 5], ['price' => 1000]));
        $this->add(self::G5, $shoes);
        $this->coupon($this->g5, 'MUNDIAL', ['value' => 50]);

        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'VERANO'])->assertSessionHasNoErrors();
        $this->assertSame('VERANO', $this->cart(self::COPPERS)['cart']['coupon']['code']);

        $g5 = $this->cart(self::G5)['cart'];

        $this->assertNull($g5['coupon']);
        $this->assertSame('0.00', $g5['discount']);
        $this->assertSame('1000.00', $g5['total']);
    }

    public function test_a_coppers_code_typed_in_g5_is_rejected_with_the_reason()
    {
        $this->cartWithCoupon();
        $shoes = $this->variant($this->product($this->g5, 'Tenis', ['27' => 5], ['price' => 1000]));
        $this->add(self::G5, $shoes);

        $this->post(self::G5.'/carrito/cupon', ['code' => 'VERANO'])
            ->assertRedirect(self::G5.'/carrito')
            ->assertSessionHasErrors(['code' => 'No aplica en esta tienda.']);

        $this->assertNull($this->cart(self::G5)['cart']['coupon']);
        $this->assertSame('1000.00', $this->cart(self::G5)['cart']['total']);
    }

    public function test_a_rejected_coupon_shows_the_reason_and_keeps_the_one_already_applied()
    {
        $this->cartWithCoupon();
        $this->coupon($this->coppers, 'VENCIDO', ['ends_at' => now()->subDay()]);
        $this->coupon($this->coppers, 'AGOTADO', ['max_uses' => 1, 'used_count' => 1]);
        $this->coupon($this->coppers, 'APAGADO', ['is_active' => false]);

        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'VERANO']);

        foreach (['VENCIDO' => 'Venció.', 'AGOTADO' => 'Ya no tiene usos.', 'APAGADO' => 'El cupón no está activo.', 'NOEXISTE' => 'El código no existe.'] as $code => $reason) {
            $this->post(self::COPPERS.'/carrito/cupon', ['code' => $code])->assertSessionHasErrors(['code' => $reason]);
        }

        $this->post(self::COPPERS.'/carrito/cupon', ['code' => ''])->assertSessionHasErrors(['code' => 'Escribe un código de descuento.']);

        $this->assertSame('VERANO', $this->cart(self::COPPERS)['cart']['coupon']['code']);
    }

    public function test_a_coupon_can_be_removed()
    {
        $this->cartWithCoupon();
        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'VERANO']);

        $this->delete(self::COPPERS.'/carrito/cupon')->assertRedirect(self::COPPERS.'/carrito')->assertSessionHas('status', 'Quitaste el cupón.');

        $cart = $this->cart(self::COPPERS)['cart'];

        $this->assertNull($cart['coupon']);
        $this->assertSame('0.00', $cart['discount']);
        $this->assertSame('1000.00', $cart['total']);
        $this->assertNull(session(CouponService::SESSION_KEY));
    }

    public function test_the_coupon_is_revalidated_every_time_the_cart_loads()
    {
        [, $coupon] = $this->cartWithCoupon();
        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'VERANO']);

        $coupon->update(['ends_at' => now()->subMinute()]);

        $props = $this->cart(self::COPPERS);

        $this->assertNull($props['cart']['coupon']);
        $this->assertSame('0.00', $props['cart']['discount']);
        $this->assertSame('1000.00', $props['cart']['total']);
        $this->assertSame(['Quitamos el cupón VERANO: Venció.'], $props['notices']);
        $this->assertNull(session(CouponService::SESSION_KEY));

        // Ya se quitó: no se repite el aviso.
        $this->assertSame([], $this->cart(self::COPPERS)['notices']);
    }

    /** Aplica el cupón, lo cambia y revisa que /carrito lo quite con ese motivo. */
    private function assertCouponDroppedAfter(callable $change, string $reason): void
    {
        [, $coupon] = $this->cartWithCoupon();
        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'VERANO'])->assertSessionHasNoErrors();

        $change($coupon);

        $props = $this->cart(self::COPPERS);

        $this->assertSame(["Quitamos el cupón VERANO: {$reason}"], $props['notices']);
        $this->assertNull($props['cart']['coupon']);
        $this->assertSame($props['cart']['subtotal'], $props['cart']['total']);
        $this->assertNull(session(CouponService::SESSION_KEY));
    }

    public function test_the_coupon_is_dropped_when_it_runs_out_of_uses()
    {
        $this->assertCouponDroppedAfter(fn (Coupon $coupon) => $coupon->update(['max_uses' => 3, 'used_count' => 3]), 'Ya no tiene usos.');
    }

    public function test_the_coupon_is_dropped_when_it_is_turned_off()
    {
        $this->assertCouponDroppedAfter(fn (Coupon $coupon) => $coupon->update(['is_active' => false]), 'El cupón no está activo.');
    }

    public function test_the_coupon_is_dropped_when_it_is_deleted()
    {
        $this->assertCouponDroppedAfter(fn (Coupon $coupon) => $coupon->delete(), 'El código no existe.');
    }

    public function test_the_coupon_is_revalidated_when_a_quantity_changes()
    {
        [$variant, $coupon] = $this->cartWithCoupon();
        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'VERANO']);

        $coupon->update(['ends_at' => now()->subMinute()]);

        $this->patch(self::COPPERS.'/carrito/'.$variant->id, ['quantity' => 3])
            ->assertRedirect(self::COPPERS.'/carrito')
            ->assertSessionHas('coupon_notice', 'Quitamos el cupón VERANO: Venció.');

        $this->assertNull(session(CouponService::SESSION_KEY));

        // La página muestra el aviso una vez, con el total ya sin descuento.
        $props = $this->cart(self::COPPERS);

        $this->assertSame(['Quitamos el cupón VERANO: Venció.'], $props['notices']);
        $this->assertSame('1500.00', $props['cart']['total']);
        $this->assertSame([], $this->cart(self::COPPERS)['notices']);
    }

    public function test_the_coupon_goes_away_quietly_when_the_cart_is_emptied()
    {
        [$variant] = $this->cartWithCoupon();
        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'VERANO']);

        $this->delete(self::COPPERS.'/carrito/'.$variant->id)->assertSessionMissing('coupon_notice');

        $this->assertNull(session(CouponService::SESSION_KEY));
        $this->assertSame([], $this->cart(self::COPPERS)['notices']);
    }

    public function test_a_coupon_does_not_apply_to_an_empty_cart()
    {
        $this->coupon($this->coppers, 'VERANO');

        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'VERANO'])
            ->assertSessionHasErrors(['code' => 'Agrega productos a tu carrito para usar un cupón.']);
    }

    // ---- Continuar con la compra --------------------------------------------------------------

    public function test_checkout_sends_guests_to_log_in()
    {
        $this->get(self::COPPERS.'/checkout')->assertRedirect(self::COPPERS.'/login');
        $this->get(self::G5.'/checkout')->assertRedirect(self::G5.'/login');
    }
}
