<?php

namespace Tests\Feature;

use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\SizeType;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Support\OrderWhatsAppMessage;
use App\Support\WhatsAppLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrderWhatsAppTest extends TestCase
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

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers', 'whatsapp_phone' => '755 111 1111']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5', 'whatsapp_phone' => '+52 (755) 222-2222']);
        $this->ana = Customer::factory()->create(['name' => 'Ana María Pérez']);
    }

    private function product(Store $store, string $name, array $extra = []): Product
    {
        $category = Category::firstOrCreate(
            ['store_id' => $store->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        $product = Product::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => $name, 'slug' => str($name)->slug(), 'price' => 500, ...$extra]);
        $product->variants()->create(['size' => 'M', 'stock' => 5]);

        return $product;
    }

    /** @param list<array{0: Product, 1: int, 2: bool}> $lines producto, cantidad, sobre pedido */
    private function order(Store $store, string $number, array $lines = [], array $extra = []): Order
    {
        $order = Order::create([
            'store_id' => $store->id,
            'customer_id' => $this->ana->id,
            'number' => $number,
            'channel' => OrderChannel::Web,
            'status' => OrderStatus::Reserved,
            'delivery_method' => DeliveryMethod::Pickup,
            'subtotal' => 3995,
            'total' => 3995,
            'expires_at' => now()->addHours(48),
            ...$extra,
        ]);

        foreach ($lines as [$product, $quantity, $backorder]) {
            $order->items()->create([
                'product_variant_id' => $product->variants()->first()->id,
                'product_name' => $product->name,
                'size' => 'M',
                'unit_price' => $product->price,
                'quantity' => $quantity,
                'is_backorder' => $backorder,
            ]);
        }

        return $order;
    }

    /** Props `whatsapp` de la página de confirmación. */
    private function links(string $host, string $number): array
    {
        $links = [];

        $this->actingAs($this->ana, 'customer')->get("{$host}/pedido/{$number}/gracias")
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$links) {
                $links = $page->toArray()['props']['whatsapp'];
            });

        return $links;
    }

    /** El texto que WhatsApp mostrará: lo que va después de ?text=, decodificado. */
    private function text(string $url): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query['text'];
    }

    // ---- 1. El número es el de la tienda del pedido ------------------------------------------

    public function test_the_link_uses_the_number_of_the_orders_store_not_the_other_one()
    {
        $this->order($this->coppers, 'CP-00001');
        $this->order($this->g5, 'G5-00001');

        $coppers = $this->links(self::COPPERS, 'CP-00001');
        $g5 = $this->links(self::G5, 'G5-00001');

        foreach (['receipt_url', 'question_url'] as $key) {
            $this->assertStringStartsWith('https://wa.me/527551111111?text=', $coppers[$key]);
            $this->assertStringStartsWith('https://wa.me/527552222222?text=', $g5[$key]);
            $this->assertStringNotContainsString('7552222222', $coppers[$key]);
            $this->assertStringNotContainsString('7551111111', $g5[$key]);
        }
    }

    public function test_without_a_store_number_there_are_no_links()
    {
        $this->coppers->update(['whatsapp_phone' => null]);
        $this->order($this->coppers, 'CP-00001');

        $this->assertSame(['receipt_url' => null, 'question_url' => null], $this->links(self::COPPERS, 'CP-00001'));

        $this->coppers->update(['whatsapp_phone' => '12345']);

        $this->assertSame(['receipt_url' => null, 'question_url' => null], $this->links(self::COPPERS, 'CP-00001'));
    }

    // ---- 2. El mensaje ---------------------------------------------------------------------------

    public function test_the_receipt_message_carries_order_number_customer_total_and_delivery()
    {
        $this->order($this->coppers, 'CP-00042', extra: ['total' => 3995.5, 'delivery_method' => DeliveryMethod::Local]);

        $text = $this->text($this->links(self::COPPERS, 'CP-00042')['receipt_url']);

        $this->assertSame(implode("\n", [
            'Hola, les envío el comprobante de pago de mi pedido.',
            '',
            'Pedido: CP-00042',
            'Nombre: Ana María Pérez',
            'Total: $3,995.50',
            'Entrega: Entrega local',
        ]), $text);
    }

    public function test_the_question_message_is_short_and_carries_the_order_number()
    {
        $this->order($this->coppers, 'CP-00042');

        $text = $this->text($this->links(self::COPPERS, 'CP-00042')['question_url']);

        $this->assertSame('Hola, tengo una duda sobre mi pedido CP-00042.', $text);
    }

    public function test_backorder_lines_add_a_notice_with_the_business_days()
    {
        $shirt = $this->product($this->coppers, 'Camisa Lino', ['allow_backorder' => true, 'backorder_days' => 7]);
        $pants = $this->product($this->coppers, 'Pantalón', ['allow_backorder' => true, 'backorder_days' => null]);
        $socks = $this->product($this->coppers, 'Calcetas');
        $order = $this->order($this->coppers, 'CP-00001', [[$shirt, 2, false], [$shirt, 3, true], [$pants, 1, true], [$socks, 1, false]]);

        $text = $this->text($this->links(self::COPPERS, 'CP-00001')['receipt_url']);

        $this->assertStringEndsWith(implode("\n", [
            'Entrega: Recoger en tienda',
            '',
            'Piezas sobre pedido:',
            '- Camisa Lino (talla M) × 3: llega en 7 días hábiles',
            '- Pantalón (talla M) × 1: tiempo de entrega por confirmar',
        ]), $text);
        $this->assertStringNotContainsString('Calcetas', $text);

        // Aunque el producto ya esté en la papelera, el aviso conserva sus días.
        $shirt->delete();
        $order->load(['customer', 'items.productVariant' => fn ($q) => $q->withTrashed()->with(['product' => fn ($p) => $p->withoutGlobalScope('store')->withTrashed()])]);

        $this->assertStringContainsString('- Camisa Lino (talla M) × 3: llega en 7 días hábiles', OrderWhatsAppMessage::receipt($order));
    }

    public function test_an_order_without_backorder_lines_has_no_backorder_notice()
    {
        $this->order($this->coppers, 'CP-00001', [[$this->product($this->coppers, 'Camisa'), 1, false]]);

        $this->assertStringNotContainsString('sobre pedido', $this->text($this->links(self::COPPERS, 'CP-00001')['receipt_url']));
    }

    public function test_accents_and_line_breaks_are_encoded_in_the_url()
    {
        $shirt = $this->product($this->coppers, 'Playera Niño & Niña 100%', ['allow_backorder' => true, 'backorder_days' => 5]);
        $this->order($this->coppers, 'CP-00001', [[$shirt, 1, true]]);

        $url = $this->links(self::COPPERS, 'CP-00001')['receipt_url'];
        $encoded = substr($url, strpos($url, '?text=') + 6);

        // Nada sin codificar: ni espacios, ni saltos de línea, ni acentos, ni & o # que corten el mensaje.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9%._~-]+$/', $encoded);
        $this->assertStringContainsString('%0A', $encoded);                 // salto de línea
        $this->assertStringContainsString('env%C3%ADo', $encoded);         // "envío" en UTF-8
        $this->assertStringContainsString('Mar%C3%ADa%20P%C3%A9rez', $encoded);
        $this->assertStringContainsString('Ni%C3%B1o%20%26%20Ni%C3%B1a%20100%25', $encoded);
        $this->assertStringContainsString('%243%2C995.00', $encoded);       // $3,995.00
        $this->assertStringNotContainsString('+', $encoded);

        // Y al decodificar queda el mensaje intacto.
        $text = $this->text($url);
        $this->assertStringContainsString("Nombre: Ana María Pérez\nTotal: \$3,995.00", $text);
        $this->assertStringContainsString('- Playera Niño & Niña 100% (talla M) × 1: llega en 5 días hábiles', $text);
    }

    // ---- 3. El teléfono --------------------------------------------------------------------------

    public function test_the_phone_is_cleaned_and_gets_the_mexico_code()
    {
        // 10 dígitos = número mexicano: se le antepone 52.
        $this->assertSame('527551234567', WhatsAppLink::number('7551234567'));
        $this->assertSame('527551234567', WhatsAppLink::number('755 123 4567'));
        $this->assertSame('527551234567', WhatsAppLink::number('(755) 123-4567'));
        $this->assertSame('527551234567', WhatsAppLink::number(" 755.123.4567\n"));

        // Si ya trae código de país no se duplica.
        $this->assertSame('527551234567', WhatsAppLink::number('+52 755 123 4567'));
        $this->assertSame('527551234567', WhatsAppLink::number('52 (755) 123-4567'));
        $this->assertSame('5217551234567', WhatsAppLink::number('+52 1 755 123 4567'));
        $this->assertSame('13055551234', WhatsAppLink::number('+1 (305) 555-1234'));

        // Sin número usable no hay enlace.
        foreach ([null, '', '   ', '12345', 'sin teléfono'] as $phone) {
            $this->assertNull(WhatsAppLink::number($phone));
            $this->assertNull(WhatsAppLink::url($phone, 'Hola'));
        }
    }

    public function test_the_url_is_wa_me_with_the_clean_number_and_an_optional_message()
    {
        $this->assertSame('https://wa.me/527551234567', WhatsAppLink::url('755 123 4567'));
        $this->assertSame('https://wa.me/527551234567', WhatsAppLink::url('755 123 4567', '   '));
        $this->assertSame('https://wa.me/527551234567?text=Hola%2C%20%C2%BFtienen%20talla%20M%3F', WhatsAppLink::url('+52 (755) 123-4567', 'Hola, ¿tienen talla M?'));
        $this->assertSame('https://wa.me/527551234567?text=uno%0Ados', WhatsAppLink::url('7551234567', "uno\ndos"));
    }

    // ---- El mismo helper en el resto de la tienda ------------------------------------------------

    public function test_the_store_and_the_product_page_use_the_same_clean_link()
    {
        $this->product($this->g5, 'Tenis');

        $this->assertSame('https://wa.me/527552222222', $this->g5->whatsappUrl());

        $this->get(self::G5.'/producto/tenis')->assertInertia(fn (Assert $page) => $page
            ->where('whatsapp.url', 'https://wa.me/527552222222')
            ->where('store.whatsapp_url', 'https://wa.me/527552222222'));

        $this->get(self::COPPERS.'/')->assertInertia(fn (Assert $page) => $page->where('store.whatsapp_url', 'https://wa.me/527551111111'));
    }

    public function test_another_customer_gets_no_links_because_the_page_is_not_theirs()
    {
        $this->order($this->coppers, 'CP-00001');

        $this->actingAs(Customer::factory()->create(), 'customer')->get(self::COPPERS.'/pedido/CP-00001/gracias')->assertNotFound();
    }
}
