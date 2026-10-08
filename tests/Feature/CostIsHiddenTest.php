<?php

namespace Tests\Feature;

use App\Enums\SizeType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** products.cost nunca llega al navegador de la tienda: ninguna página Inertia lo lleva. */
class CostIsHiddenTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://coppers.localhost';

    /** Un costo fácil de reconocer en cualquier respuesta. */
    private const COST = '734.19';

    public function test_no_page_of_the_store_carries_the_product_cost()
    {
        $store = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers']);
        $category = Category::create(['store_id' => $store->id, 'name' => 'General', 'slug' => 'general', 'size_type' => SizeType::OneSize]);
        $product = Product::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => 'Camisa lino', 'slug' => 'camisa-lino', 'price' => 900, 'cost' => self::COST]);
        $variant = $product->variants()->create(['size' => 'M', 'stock' => 5]);
        $customer = Customer::factory()->create(['phone' => '7551234567']);

        $this->assertSame(self::COST, Product::query()->where('store_id', $store->id)->value('cost'));

        $pages = [
            '/' => 'home',
            '/catalogo' => 'catalog',
            '/categoria/general' => 'catalog',
            '/producto/camisa-lino' => 'product',
            '/carrito' => 'cart',
        ];

        $this->post(self::HOST.'/carrito', ['variant_id' => $variant->id, 'quantity' => 1])->assertSessionHasNoErrors();

        foreach ($pages as $path => $component) {
            $this->assertNoCost($this->get(self::HOST.$path)->assertOk(), $component, $path);
        }

        $this->actingAs($customer, 'customer');

        $this->assertNoCost($this->get(self::HOST.'/checkout')->assertOk(), 'checkout', '/checkout');

        $this->post(self::HOST.'/checkout', ['delivery_method' => 'recoger', 'phone' => '7551234567'])->assertSessionHasNoErrors();
        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->assertNoCost($this->get(self::HOST."/pedido/{$order->number}/gracias")->assertOk(), 'order-thanks', 'gracias');
        $this->assertNoCost($this->get(self::HOST.'/mi-cuenta/pedidos')->assertOk(), 'my-orders', 'mis pedidos');
        $this->assertNoCost($this->get(self::HOST."/mi-cuenta/pedidos/{$order->number}")->assertOk(), 'my-order', 'detalle del pedido');
        $this->assertNoCost($this->get(self::HOST.'/mi-cuenta')->assertOk(), 'account', 'mi cuenta');
    }

    public function test_the_model_never_serializes_its_cost()
    {
        $store = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost']);
        $category = Category::create(['store_id' => $store->id, 'name' => 'General', 'slug' => 'general', 'size_type' => SizeType::OneSize]);
        $product = Product::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => 'Camisa', 'slug' => 'camisa', 'price' => 900, 'cost' => self::COST]);

        $this->assertArrayNotHasKey('cost', $product->toArray());
        $this->assertStringNotContainsString('cost', $product->toJson());
        // Para el código del servidor y el panel del owner sigue disponible.
        $this->assertSame(self::COST, $product->cost);
    }

    /** La página no lleva la llave «cost» ni el valor del costo en ninguna parte. */
    private function assertNoCost($response, string $component, string $label): void
    {
        $props = null;

        $response->assertInertia(function (Assert $page) use ($component, &$props) {
            $page->component($component);
            $props = $page->toArray()['props'];
        });

        // Las opciones de entrega llevan el costo del ENVÍO ('cost'), que es público y no es el costo del producto.
        unset($props['deliveryOptions']);

        $json = json_encode($props);

        $this->assertDoesNotMatchRegularExpression('/"cost"\s*:/', $json, "La página {$label} lleva la llave cost.");
        $this->assertStringNotContainsString(self::COST, $json, "La página {$label} lleva el costo del producto.");
        $this->assertStringNotContainsString(self::COST, $response->getContent(), "El HTML de {$label} lleva el costo del producto.");
    }
}
