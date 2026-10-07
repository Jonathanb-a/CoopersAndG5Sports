<?php

namespace Tests\Feature;

use App\Enums\DeliveryAttemptResult;
use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\SizeType;
use App\Enums\UserRole;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderDeliveryService;
use App\Services\OrderStatusService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

/** Entrega local desde el checkout: el punto de entrega llega al WhatsApp y al panel. */
class LocalDeliveryCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const COPPERS = 'http://coppers.localhost';

    private Store $coppers;

    private Customer $ana;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-10-05 10:30:00');

        $this->coppers = Store::create([
            'name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers',
            'whatsapp_phone' => '755 111 1111', 'local_shipping_cost' => 80,
        ]);
        $this->ana = Customer::factory()->create(['name' => 'Ana Pérez']);
        $this->owner = User::factory()->create(['name' => 'Dueña Olga', 'role' => UserRole::Owner]);
    }

    private function variant(string $name = 'Camisa'): ProductVariant
    {
        $category = Category::firstOrCreate(
            ['store_id' => $this->coppers->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        return $category->products()
            ->create(['store_id' => $this->coppers->id, 'name' => $name, 'slug' => str($name)->slug(), 'price' => 500])
            ->variants()->create(['size' => 'M', 'stock' => 10]);
    }

    private function place(array $data): Order
    {
        $this->actingAs($this->ana, 'customer');
        $this->post(self::COPPERS.'/carrito', ['variant_id' => $this->variant('Camisa '.Order::withoutGlobalScopes()->count())->id, 'quantity' => 1])
            ->assertSessionHasNoErrors();
        $this->post(self::COPPERS.'/checkout', ['phone' => '755 123 4567', ...$data])->assertSessionHasNoErrors();

        return Order::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function local(array $extra = []): array
    {
        return ['delivery_method' => 'local', 'local' => ['name' => 'Ana Pérez', 'point' => 'Hotel Sol, lobby', ...$extra]];
    }

    /** Texto del mensaje de comprobante que arma la página de gracias. */
    private function receiptText(Order $order): string
    {
        $url = null;

        $this->actingAs($this->ana, 'customer')->get(self::COPPERS."/pedido/{$order->number}/gracias")->assertOk()
            ->assertInertia(function (Assert $page) use (&$url) {
                $url = $page->toArray()['props']['whatsapp']['receipt_url'];
            });

        $this->assertStringStartsWith('https://wa.me/527551111111?text=', $url);

        return rawurldecode(substr($url, strpos($url, '?text=') + 6));
    }

    private function panel(): void
    {
        $this->actingAs($this->owner, 'web');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($this->coppers);
    }

    // ---- WhatsApp ---------------------------------------------------------------------------------

    public function test_the_whatsapp_message_of_a_local_order_includes_the_delivery_point()
    {
        $order = $this->place($this->local([
            'name' => 'Luisa Pérez', 'references' => 'Frente a la playa',
        ]));

        $this->assertSame(implode("\n", [
            "Hola Copper's, adjunto el comprobante de transferencia de mi pedido.",
            '',
            "Pedido: {$order->number}",
            'Nombre: Ana Pérez',
            'Total: $580.00',
            'Entrega: Entrega local',
            'Punto de entrega: Hotel Sol, lobby',
            'Referencias: Frente a la playa',
            'Recibe: Luisa Pérez',
        ]), $this->receiptText($order));
    }

    public function test_a_pickup_order_message_carries_no_delivery_point()
    {
        $local = $this->place($this->local());
        $text = $this->receiptText($local);

        $this->assertStringContainsString("Entrega: Entrega local\nPunto de entrega: Hotel Sol, lobby", $text);
        $this->assertStringNotContainsString('Referencias', $text);
        $this->assertStringNotContainsString('Recibe', $text);   // recibe la misma clienta

        $pickup = $this->place(['delivery_method' => 'recoger']);
        $text = $this->receiptText($pickup);

        $this->assertStringContainsString('Entrega: Recoger en tienda', $text);
        $this->assertStringNotContainsString('Punto de entrega', $text);
    }

    // ---- Panel ------------------------------------------------------------------------------------

    public function test_the_panel_shows_the_proposed_point()
    {
        $order = $this->place($this->local(['references' => 'Frente a la playa']));
        $this->panel();

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('Entrega a domicilio')
            ->assertSee('Punto de entrega propuesto')
            ->assertSee('Hotel Sol, lobby')
            ->assertSee('Frente a la playa')
            ->assertDontSee('Preferencia')
            ->assertSee('Sin programar');
    }

    public function test_the_admin_confirms_the_date_and_the_rest_of_the_delivery_flow_still_works()
    {
        $order = $this->place($this->local());
        app(OrderStatusService::class)->transition($order, OrderStatus::Paid, $this->owner);
        $this->panel();

        // El formulario de programar arranca vacío: el admin captura lo que acordó por WhatsApp.
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->mountAction('scheduleDelivery')
            ->assertActionDataSet(['date' => null, 'window' => null])
            ->setActionData(['date' => '2026-10-09', 'window' => 'De 10 a 12'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $order->refresh();

        $this->assertSame(['2026-10-09', 'De 10 a 12'], [$order->delivery_scheduled_date->toDateString(), $order->delivery_window]);

        // Lo ya construido sigue igual: un intento fallido limpia la fecha confirmada.
        app(OrderStatusService::class)->transition($order, OrderStatus::Shipped, $this->owner);
        app(OrderDeliveryService::class)->recordAttempt($order, DeliveryAttemptResult::NotHome, null, $this->owner);
        app(OrderDeliveryService::class)->recordAttempt($order, DeliveryAttemptResult::NotHome, null, $this->owner);

        $order->refresh();
        $this->assertNull($order->delivery_scheduled_date);
        $this->assertTrue(OrderDeliveryService::canSwitchToPickup($order));

        app(OrderDeliveryService::class)->switchToPickup($order, $this->owner);
        $this->assertSame(DeliveryMethod::Pickup, $order->refresh()->delivery_method);
        $this->assertSame('580.00', $order->total);
    }
}
