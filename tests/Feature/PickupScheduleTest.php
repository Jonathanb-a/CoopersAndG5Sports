<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\SizeType;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransitionException;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderCancellationService;
use App\Services\OrderDeliveryService;
use App\Services\OrderStatusService;
use App\Services\OverdueCashOrders;
use App\Support\OrderWhatsAppMessage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

/** Programar recogida en tienda: fecha (y hora) que acuerda el admin con el cliente. */
class PickupScheduleTest extends TestCase
{
    use RefreshDatabase;

    private const COPPERS = 'http://coppers.localhost';

    private Store $coppers;

    private Store $g5;

    private Customer $ana;

    private User $owner;

    private User $coppersStaff;

    private User $g5Staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-10-07 10:00:00');

        $this->coppers = Store::create([
            'name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers',
            'whatsapp_phone' => '755 111 1111', 'local_shipping_cost' => 80,
            'pickup_address' => 'Av. Juan Álvarez 12, Centro, Zihuatanejo', 'pickup_hours' => 'Lun a Sáb 10:00 a 19:00',
        ]);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);
        $this->ana = Customer::factory()->create(['name' => 'Ana Pérez', 'phone' => '7551234567']);
        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->coppersStaff = User::factory()->create(['name' => 'Laura Staff', 'role' => UserRole::Staff]);
        $this->coppersStaff->stores()->attach($this->coppers->id);
        $this->g5Staff = User::factory()->create(['name' => 'Gabriel Staff', 'role' => UserRole::Staff]);
        $this->g5Staff->stores()->attach($this->g5->id);

        config(['services.telegram.bot_token' => '123:TOKEN', 'services.telegram.owner_chat_id' => '99887766']);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function variant(): ProductVariant
    {
        $category = Category::firstOrCreate(
            ['store_id' => $this->coppers->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        $count = ProductVariant::count() + 1;

        return $category->products()
            ->create(['store_id' => $this->coppers->id, 'name' => "Camisa {$count}", 'slug' => "camisa-{$count}", 'price' => 500])
            ->variants()->create(['size' => 'M', 'stock' => 10]);
    }

    /** Aparta por la tienda en línea y devuelve el pedido (recoger en tienda si no se dice otra cosa). */
    private function place(string $payment = 'transfer', array $extra = []): Order
    {
        $this->actingAs($this->ana, 'customer');
        $this->post(self::COPPERS.'/carrito', ['variant_id' => $this->variant()->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post(self::COPPERS.'/checkout', ['delivery_method' => 'recoger', 'payment_method' => $payment, 'phone' => '755 123 4567', ...$extra])
            ->assertSessionHasNoErrors();

        return Order::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function local(string $payment = 'transfer'): Order
    {
        return $this->place($payment, ['delivery_method' => 'local', 'local' => ['name' => 'Ana Pérez', 'point' => 'Hotel Sol, lobby']]);
    }

    private function confirm(Order $order): Order
    {
        return app(OrderStatusService::class)->transition($order, OrderStatus::Paid, $this->owner, data: $order->paysInCash() ? [] : ['payment_reference' => 'ABC']);
    }

    private function pickup(Order $order, string $date, ?string $window = null, ?User $user = null): Order
    {
        return app(OrderDeliveryService::class)->schedulePickup($order, $date, $window, $user ?? $this->coppersStaff);
    }

    private function panel(User $user, ?Store $store = null): void
    {
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($store ?? $this->coppers);
    }

    private function detail(Order $order)
    {
        return Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);
    }

    private function refuses(callable $callback): string
    {
        try {
            $callback();
        } catch (InvalidOrderTransitionException $exception) {
            return $exception->getMessage();
        }

        $this->fail('Se esperaba que la operación fuera rechazada.');
    }

    private function props(string $url): array
    {
        $props = [];

        $this->actingAs($this->ana, 'customer')->get($url)->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    // ---- Quién y cuándo ---------------------------------------------------------------------------

    public function test_the_action_shows_for_pickup_orders_that_are_reserved_or_paid_only()
    {
        $reserved = $this->place();
        $paid = $this->confirm($this->place());
        $cashConfirmed = $this->confirm($this->place('cash'));
        $shipped = $this->confirm($this->place());
        app(OrderStatusService::class)->transition($shipped, OrderStatus::Shipped, $this->owner);
        $delivered = $this->confirm($this->place());
        app(OrderStatusService::class)->transition($delivered, OrderStatus::Shipped, $this->owner);
        app(OrderStatusService::class)->transition($delivered, OrderStatus::Delivered, $this->owner);
        $local = $this->local();
        $this->panel($this->coppersStaff);

        foreach ([$reserved, $paid, $cashConfirmed] as $order) {
            $this->detail($order)->assertActionVisible('schedulePickup')->assertActionHasLabel('schedulePickup', 'Programar recogida');
        }

        foreach ([$shipped, $delivered, $local] as $order) {
            $this->detail($order)->assertActionHidden('schedulePickup');
        }

        // La entrega a domicilio sigue donde estaba: no aparece en un pedido para recoger.
        $this->detail($reserved)->assertActionHidden('scheduleDelivery');

        // Por el servicio, lo mismo.
        $this->assertStringContainsString('solo los pedidos para recoger en tienda apartados o pagados', $this->refuses(fn () => $this->pickup($local, '2026-10-09')));
        $this->assertStringContainsString('solo los pedidos para recoger', $this->refuses(fn () => $this->pickup($shipped, '2026-10-09')));
        $this->refuses(fn () => app(OrderDeliveryService::class)->schedule($reserved, '2026-10-09', null, $this->owner));
    }

    public function test_a_past_date_is_rejected_and_today_with_no_time_is_fine()
    {
        $order = $this->place();

        $this->assertSame('La fecha de recogida no puede ser anterior a hoy.', $this->refuses(fn () => $this->pickup($order, '2026-10-06', 'Tarde')));
        $this->assertNull($order->fresh()->delivery_scheduled_date);
        $this->assertCount(1, $order->statusHistories()->get());

        $this->panel($this->coppersStaff);
        $this->detail($order)
            ->callAction('schedulePickup', data: ['date' => '2026-10-06', 'window' => null])
            ->assertHasFormErrors(['date']);
        $this->detail($order)
            ->callAction('schedulePickup', data: ['date' => null, 'window' => '4 pm'])
            ->assertHasFormErrors(['date' => 'required']);
        $this->assertNull($order->fresh()->delivery_scheduled_date);

        // Hoy, sin hora: la hora es opcional.
        $this->detail($order)
            ->callAction('schedulePickup', data: ['date' => '2026-10-07', 'window' => null])
            ->assertHasNoFormErrors();

        $order->refresh();
        $this->assertTrue($order->delivery_scheduled_date->isToday());
        $this->assertNull($order->delivery_window);
    }

    // ---- Vencimiento ------------------------------------------------------------------------------

    public function test_in_cash_the_expiry_moves_to_the_end_of_the_day_with_the_seven_day_cap_and_transfer_does_not_change()
    {
        $cash = $this->place('cash');
        $transfer = $this->place('transfer');
        $transferExpiry = $transfer->expires_at->toDateTimeString();

        $this->pickup($cash, '2026-10-09');
        $this->pickup($transfer, '2026-10-09');

        $this->assertSame('2026-10-09 23:59:59', $cash->fresh()->expires_at->toDateTimeString());
        $this->assertSame($transferExpiry, $transfer->fresh()->expires_at->toDateTimeString());

        // Tope de shop.cash_reserve_max_days (7 días desde que se creó el pedido).
        $this->pickup($cash, '2026-10-30');
        $this->assertSame('2026-10-14 10:00:00', $cash->fresh()->expires_at->toDateTimeString());
        $this->pickup($cash, '2026-10-14');
        $this->assertSame('2026-10-14 10:00:00', $cash->fresh()->expires_at->toDateTimeString());
        $this->pickup($cash, '2026-10-13');
        $this->assertSame('2026-10-13 23:59:59', $cash->fresh()->expires_at->toDateTimeString());

        // El pedido sigue en su estado y con sus piezas.
        $this->assertSame(OrderStatus::Reserved, $cash->fresh()->status);
    }

    public function test_a_cash_pickup_past_its_date_triggers_the_same_not_delivered_notice()
    {
        $cash = $this->confirm($this->place('cash'));
        $this->pickup($cash, '2026-10-08');
        $transfer = $this->confirm($this->place('transfer'));
        $this->pickup($transfer, '2026-10-08');

        // Con la fecha vigente no se avisa.
        $this->travelTo('2026-10-08 12:00:00');
        $this->artisan('orders:expire-unpaid')->expectsOutputToContain('0 pedidos cancelados')->assertSuccessful();
        $this->assertNull($cash->fresh()->cash_overdue_notified_at);

        // Pasó la fecha sin recogerse: aviso al dueño, y el pedido no se cancela solo.
        $this->travelTo('2026-10-09 08:00:00');
        $this->artisan('orders:expire-unpaid')->expectsOutputToContain('1 pedidos en efectivo sin entregar avisados al dueño')->assertSuccessful();

        $this->assertSame(OrderStatus::Paid, $cash->fresh()->status);
        $this->assertNotNull($cash->fresh()->cash_overdue_notified_at);
        $this->assertNull($transfer->fresh()->cash_overdue_notified_at);
        $this->assertSame(1, $this->owner->notifications()->get()->filter(fn ($n) => $n->data['title'] === OverdueCashOrders::TITLE)->count());

        $texts = Http::recorded()->map(fn (array $pair) => $pair[0]['text'])->all();
        $this->assertContains(
            "Copper's · CP-00001 · Pedido en efectivo sin entregar\nCliente: Ana Pérez\nPor cobrar: \$500.00\nEntrega: Recoger en tienda\nPasó su fecha: 08/10/2026 23:59",
            $texts,
        );
    }

    // ---- Historial --------------------------------------------------------------------------------

    public function test_the_change_is_recorded_in_the_history_with_who_did_it()
    {
        $order = $this->confirm($this->place());

        $this->pickup($order, '2026-10-09', 'Entre 4 y 6 pm', $this->coppersStaff);
        $this->pickup($order, '2026-10-10', null, $this->owner);

        $rows = $order->statusHistories()->get()->slice(-2)->values();

        $this->assertSame('Recogida programada para 09/10/2026 (Entre 4 y 6 pm).', $rows[0]->note);
        $this->assertSame($this->coppersStaff->id, $rows[0]->user_id);
        $this->assertSame('Recogida programada para 10/10/2026.', $rows[1]->note);
        $this->assertSame($this->owner->id, $rows[1]->user_id);
        // Misma etapa antes y después: es una anotación, no un cambio de estado.
        $this->assertSame([OrderStatus::Paid, OrderStatus::Paid], [$rows[0]->from_status, $rows[0]->to_status]);

        // En el detalle del panel se ve quién lo hizo.
        $this->panel($this->owner);
        $this->detail($order)
            ->assertSee('Recogida programada')
            ->assertSee('Recogida programada para 09/10/2026 (Entre 4 y 6 pm).')
            ->assertSee('Laura Staff');
    }

    // ---- Lo que ve el cliente ---------------------------------------------------------------------

    public function test_the_customer_sees_when_to_pick_up_next_to_the_store_address_and_hours()
    {
        $order = $this->place();
        $none = ['configured' => true, 'schedule' => null];

        // Sin fecha: solo dirección y horario.
        foreach (['/pedido/CP-00001/gracias', '/mi-cuenta/pedidos/CP-00001'] as $path) {
            $pickup = $this->props(self::COPPERS.$path)['pickup'];
            $this->assertSame([true, null], [$pickup['configured'], $pickup['schedule']]);
        }

        $this->pickup($order, '2026-10-08', 'Entre 4 y 6 pm');

        foreach (['/pedido/CP-00001/gracias', '/mi-cuenta/pedidos/CP-00001'] as $path) {
            $pickup = $this->props(self::COPPERS.$path)['pickup'];

            $this->assertSame('Recoge el jueves 8 de octubre de 2026 · Entre 4 y 6 pm', $pickup['schedule']);
            $this->assertSame('Av. Juan Álvarez 12, Centro, Zihuatanejo', $pickup['address']);
            $this->assertSame('Lun a Sáb 10:00 a 19:00', $pickup['hours']);
        }

        // Sin hora: solo la fecha.
        $this->pickup($order, '2026-10-09');
        $this->assertSame('Recoge el viernes 9 de octubre de 2026', $this->props(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')['pickup']['schedule']);

        // Cancelado: ya no hay nada que recoger.
        app(OrderCancellationService::class)->cancel($order, $this->owner, 'Cliente pidió cancelar');
        $this->assertNull($this->props(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')['pickup']['schedule']);

        // Una entrega a domicilio no lleva el recuadro de recoger.
        $this->local();
        $this->assertNull($this->props(self::COPPERS.'/mi-cuenta/pedidos/CP-00002')['pickup']);
    }

    // ---- WhatsApp ---------------------------------------------------------------------------------

    public function test_the_whatsapp_button_tells_the_customer_the_date_with_the_order_number_and_the_store_address()
    {
        $order = $this->place();
        $this->pickup($order, '2026-10-08', 'Entre 4 y 6 pm');
        $this->panel($this->coppersStaff);

        $text = "Hola Ana Pérez, tu pedido CP-00001 queda para recoger el jueves 8 de octubre de 2026 · Entre 4 y 6 pm.\n"
            ."Dirección: Av. Juan Álvarez 12, Centro, Zihuatanejo\nCopper's";

        $this->assertSame($text, OrderWhatsAppMessage::statusNotice($order->fresh()->load('store', 'customer')));

        // El botón de avisar del detalle abre el chat del cliente con ese mensaje.
        $url = 'https://wa.me/527551234567?text='.rawurlencode($text);
        $this->assertSame($url, OrderWhatsAppMessage::statusNoticeUrl($order->fresh()->load('store', 'customer')));
        $this->detail($order)->assertActionVisible('whatsapp')->assertActionHasUrl('whatsapp', $url);

        // Al programar, la notificación trae el mismo botón.
        $fresh = $this->place();
        $this->panel($this->coppersStaff);
        $this->detail($fresh)
            ->callAction('schedulePickup', data: ['date' => '2026-10-08', 'window' => '5 pm'])
            ->assertNotified('Recogida programada para el 08/10/2026');

        // Pagado también; y listo para recoger conserva su propio aviso de siempre.
        $this->confirm($fresh);
        $this->assertStringContainsString('queda para recoger el jueves 8 de octubre de 2026 · 5 pm', OrderWhatsAppMessage::statusNotice($fresh->fresh()->load('store', 'customer')));
        app(OrderStatusService::class)->transition($fresh, OrderStatus::Shipped, $this->owner);
        $this->assertStringContainsString('ya está listo para recoger', OrderWhatsAppMessage::statusNotice($fresh->fresh()->load('store', 'customer')));
    }

    // ---- Permisos ---------------------------------------------------------------------------------

    public function test_only_staff_of_that_store_can_schedule_a_pickup()
    {
        $order = $this->place();

        $message = $this->refuses(fn () => $this->pickup($order, '2026-10-09', null, $this->g5Staff));
        $this->assertSame('No tienes acceso a este pedido.', $message);
        $this->assertNull($order->fresh()->delivery_scheduled_date);
        $this->assertCount(1, $order->statusHistories()->get());

        // En el panel ni siquiera llega al pedido.
        $this->actingAs($this->g5Staff, 'web');
        $this->get("/admin/coppers/orders/{$order->id}")->assertNotFound();
        $this->get("/admin/g5/orders/{$order->id}")->assertNotFound();

        // El owner y el staff de la tienda sí.
        $this->pickup($order, '2026-10-09', null, $this->owner);
        $this->pickup($order, '2026-10-10', null, $this->coppersStaff);
        $this->assertTrue($order->fresh()->delivery_scheduled_date->isSameDay('2026-10-10'));
    }
}
