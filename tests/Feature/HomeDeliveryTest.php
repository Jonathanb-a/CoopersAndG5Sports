<?php

namespace Tests\Feature;

use App\Enums\DeliveryAttemptResult;
use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransitionException;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Widgets\HomeDeliveries;
use App\Models\Customer;
use App\Models\DeliveryAttempt;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderDeliveryService;
use App\Services\OrderStatusService;
use App\Support\OrderWhatsAppMessage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

class HomeDeliveryTest extends TestCase
{
    use RefreshDatabase;

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

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers', 'whatsapp_phone' => '755 111 1111']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5', 'whatsapp_phone' => '755 222 2222']);
        $this->ana = Customer::factory()->create(['name' => 'Ana Pérez', 'phone' => '7551234567']);
        $this->owner = User::factory()->create(['name' => 'Dueña Olga', 'role' => UserRole::Owner]);
        $this->coppersStaff = User::factory()->create(['name' => 'Laura Staff', 'role' => UserRole::Staff]);
        $this->coppersStaff->stores()->attach($this->coppers->id);
        $this->g5Staff = User::factory()->create(['name' => 'Gabriel Staff', 'role' => UserRole::Staff]);
        $this->g5Staff->stores()->attach($this->g5->id);
    }

    private function order(string $number, OrderStatus $status = OrderStatus::Paid, DeliveryMethod $method = DeliveryMethod::Local, ?Store $store = null, array $extra = []): Order
    {
        $order = Order::create([
            'store_id' => ($store ?? $this->coppers)->id,
            'customer_id' => $this->ana->id,
            'number' => $number,
            'channel' => OrderChannel::Web,
            'status' => $status,
            'delivery_method' => $method,
            'shipping_address' => $method === DeliveryMethod::Pickup ? null : [
                'name' => 'Ana Pérez',
                'phone' => '7559998877',
                'street' => 'Av. del Mar 12',
                'neighborhood' => 'Centro',
                'city' => 'Zihuatanejo',
                'state' => 'Guerrero',
                'zip' => '40880',
            ],
            'subtotal' => 1000,
            'discount' => 0,
            'shipping_cost' => 80,
            'total' => 1080,
            'paid_at' => $status === OrderStatus::Reserved ? null : now(),
            ...$extra,
        ]);

        app(OrderStatusService::class)->recordInitial($order, $this->ana);

        return $order;
    }

    private function deliveries(): OrderDeliveryService
    {
        return app(OrderDeliveryService::class);
    }

    private function failedAttempt(Order $order, DeliveryAttemptResult $result = DeliveryAttemptResult::NotHome, ?string $notes = null, ?User $actor = null): DeliveryAttempt
    {
        return $this->deliveries()->recordAttempt($order, $result, $notes, $actor ?? $this->coppersStaff);
    }

    private function panelAs(User $user, Store $store): void
    {
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($store);
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

        $this->fail('Se esperaba que el servicio rechazara la operación.');
    }

    private function props(Order $order): array
    {
        $props = [];
        $domain = $order->store_id === $this->g5->id ? 'g5.localhost' : 'coppers.localhost';

        $this->actingAs($this->ana, 'customer')->get("http://{$domain}/mi-cuenta/pedidos/{$order->number}")->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    // ---- 1. Programar: solo método local y pedido pagado o enviado --------------------------------

    public function test_scheduling_only_works_on_local_orders_that_are_paid_or_shipped()
    {
        $date = today()->addDays(2);
        $paid = $this->order('CP-00001');
        $shipped = $this->order('CP-00002', OrderStatus::Shipped);

        $this->deliveries()->schedule($paid, $date, ' Entre 4 y 6 pm ', $this->coppersStaff);
        $this->deliveries()->schedule($shipped, $date, 'Por la mañana', $this->owner);

        $this->assertTrue($paid->delivery_scheduled_date->isSameDay($date));
        $this->assertSame('Entre 4 y 6 pm', $paid->delivery_window);
        $this->assertSame(OrderStatus::Paid, $paid->status);
        $this->assertTrue($shipped->delivery_scheduled_date->isSameDay($date));

        // Queda en el historial con la misma etapa y una nota.
        $row = $paid->statusHistories()->get()->last();
        $this->assertSame(OrderStatus::Paid, $row->from_status);
        $this->assertSame(OrderStatus::Paid, $row->to_status);
        $this->assertSame($this->coppersStaff->id, $row->user_id);
        $this->assertSame('Entrega programada para '.$date->format('d/m/Y').' (Entre 4 y 6 pm).', $row->note);

        $refused = [
            $this->order('CP-00003', OrderStatus::Reserved),
            $this->order('CP-00004', OrderStatus::Delivered),
            $this->order('CP-00005', OrderStatus::Cancelled),
            $this->order('CP-00006', OrderStatus::Paid, DeliveryMethod::Pickup),
            $this->order('CP-00007', OrderStatus::Shipped, DeliveryMethod::National),
        ];

        foreach ($refused as $order) {
            $this->refuses(fn () => $this->deliveries()->schedule($order, $date, 'Tarde', $this->owner));
            $this->assertNull($order->fresh()->delivery_scheduled_date);
            $this->assertCount(1, $order->statusHistories()->get());
        }

        // En el panel la acción solo aparece donde aplica.
        $this->panelAs($this->coppersStaff, $this->coppers);
        $this->detail($this->order('CP-00010'))->assertActionVisible('scheduleDelivery');
        $this->detail($this->order('CP-00011', OrderStatus::Shipped))->assertActionVisible('scheduleDelivery');

        foreach ($refused as $order) {
            $this->detail($order)->assertActionHidden('scheduleDelivery');
        }
    }

    public function test_the_panel_action_schedules_and_reschedules()
    {
        $order = $this->order('CP-00001');
        $this->panelAs($this->coppersStaff, $this->coppers);

        $this->detail($order)
            ->assertActionHasLabel('scheduleDelivery', 'Programar entrega')
            ->callAction('scheduleDelivery', data: ['date' => today()->toDateString(), 'window' => 'Entre 4 y 6 pm'])
            ->assertHasNoFormErrors();

        $this->assertTrue($order->refresh()->delivery_scheduled_date->isToday());
        $this->assertSame('Entre 4 y 6 pm', $order->delivery_window);

        $this->detail($order)
            ->assertActionHasLabel('scheduleDelivery', 'Reprogramar entrega')
            ->assertSee('Entre 4 y 6 pm')
            ->callAction('scheduleDelivery', data: ['date' => today()->addDays(3)->toDateString(), 'window' => 'Después de las 7 pm'])
            ->assertHasNoFormErrors();

        $this->assertTrue($order->refresh()->delivery_scheduled_date->isSameDay(today()->addDays(3)));
        $this->assertSame('Después de las 7 pm', $order->delivery_window);
        $this->assertSame(2, $order->statusHistories()->where('note', 'like', 'Entrega programada para%')->count());
    }

    // ---- 2. No acepta una fecha pasada ----------------------------------------------------------

    public function test_a_past_date_is_not_accepted()
    {
        $order = $this->order('CP-00001');

        $message = $this->refuses(fn () => $this->deliveries()->schedule($order, today()->subDay(), 'Tarde', $this->owner));
        $this->assertSame('La fecha de entrega no puede ser anterior a hoy.', $message);
        $this->assertNull($order->fresh()->delivery_scheduled_date);

        $this->panelAs($this->coppersStaff, $this->coppers);
        $this->detail($order)
            ->callAction('scheduleDelivery', data: ['date' => today()->subDay()->toDateString(), 'window' => 'Tarde'])
            ->assertHasFormErrors(['date']);

        $this->assertNull($order->fresh()->delivery_scheduled_date);
        $this->assertCount(1, $order->statusHistories()->get());

        // Hoy sí se vale.
        $this->deliveries()->schedule($order, today(), 'Tarde', $this->owner);
        $this->assertTrue($order->fresh()->delivery_scheduled_date->isToday());
    }

    // ---- 3. «Entregado» pasa el pedido a entregado ----------------------------------------------

    public function test_recording_delivered_moves_the_order_to_delivered_and_sets_delivered_at()
    {
        $order = $this->order('CP-00001', OrderStatus::Shipped, extra: ['delivery_scheduled_date' => today(), 'delivery_window' => 'Tarde']);
        $this->panelAs($this->coppersStaff, $this->coppers);

        $this->detail($order)
            ->assertActionVisible('recordAttempt')
            ->callAction('recordAttempt', data: ['result' => DeliveryAttemptResult::Delivered->value, 'attempted_at' => today()->toDateString()])
            ->assertHasNoFormErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertNotNull($order->delivered_at);

        $attempt = $order->deliveryAttempts()->sole();
        $this->assertSame(DeliveryAttemptResult::Delivered, $attempt->result);
        $this->assertSame($this->coppersStaff->id, $attempt->user_id);
        $this->assertTrue($attempt->attempted_at->isToday());

        // El cambio de estado pasó por OrderStatusService: quedó en el historial.
        $row = $order->statusHistories()->get()->last();
        $this->assertSame(OrderStatus::Shipped, $row->from_status);
        $this->assertSame(OrderStatus::Delivered, $row->to_status);

        // Ya entregado no admite más intentos.
        $this->detail($order)->assertActionHidden('recordAttempt');
        $this->refuses(fn () => $this->failedAttempt($order));
        $this->assertCount(1, $order->deliveryAttempts()->get());
    }

    public function test_attempts_are_only_for_shipped_local_orders()
    {
        $orders = [
            $this->order('CP-00001', OrderStatus::Paid),
            $this->order('CP-00002', OrderStatus::Reserved),
            $this->order('CP-00003', OrderStatus::Shipped, DeliveryMethod::Pickup),
            $this->order('CP-00004', OrderStatus::Shipped, DeliveryMethod::National),
        ];
        $this->panelAs($this->owner, $this->coppers);

        foreach ($orders as $order) {
            $this->refuses(fn () => $this->deliveries()->recordAttempt($order, DeliveryAttemptResult::Delivered, null, $this->owner));
            $this->assertSame(0, DeliveryAttempt::count());
            $this->assertNull($order->fresh()->delivered_at);
            $this->detail($order)->assertActionHidden('recordAttempt');
        }
    }

    // ---- 4. «No estaba» guarda el intento, limpia la fecha y el pedido sigue enviado ---------------

    public function test_recording_not_home_keeps_the_order_shipped_and_clears_the_date()
    {
        $order = $this->order('CP-00001', OrderStatus::Shipped, extra: ['delivery_scheduled_date' => today(), 'delivery_window' => 'Entre 4 y 6 pm']);
        $this->panelAs($this->coppersStaff, $this->coppers);

        $this->detail($order)
            ->callAction('recordAttempt', data: [
                'result' => DeliveryAttemptResult::NotHome->value,
                'notes' => 'Tocamos y nadie abrió',
                'attempted_at' => today()->toDateString(),
            ])
            ->assertHasNoFormErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::Shipped, $order->status);
        $this->assertNull($order->delivered_at);
        $this->assertNull($order->delivery_scheduled_date);
        $this->assertNull($order->delivery_window);
        $this->assertTrue($order->needsDeliveryReschedule());

        $attempt = $order->deliveryAttempts()->sole();
        $this->assertSame(DeliveryAttemptResult::NotHome, $attempt->result);
        $this->assertSame('Tocamos y nadie abrió', $attempt->notes);
        $this->assertSame($this->coppersStaff->id, $attempt->user_id);

        // El detalle lista el intento con resultado, nota, fecha y quién lo registró.
        $this->detail($order)
            ->assertSee('Intentos de entrega')
            ->assertSee('No estaba')
            ->assertSee('Tocamos y nadie abrió')
            ->assertSee(today()->format('d/m/Y'))
            ->assertSee('Laura Staff')
            ->assertSee('Por reprogramar');
    }

    public function test_the_note_is_required_when_the_result_is_other()
    {
        $order = $this->order('CP-00001', OrderStatus::Shipped);
        $this->panelAs($this->coppersStaff, $this->coppers);

        $this->detail($order)
            ->callAction('recordAttempt', data: ['result' => DeliveryAttemptResult::Other->value, 'notes' => null, 'attempted_at' => today()->toDateString()])
            ->assertHasFormErrors(['notes']);
        $this->refuses(fn () => $this->failedAttempt($order, DeliveryAttemptResult::Other, '  '));
        $this->assertSame(0, DeliveryAttempt::count());

        // Una fecha futura tampoco.
        $this->detail($order)
            ->callAction('recordAttempt', data: ['result' => DeliveryAttemptResult::NotHome->value, 'attempted_at' => today()->addDay()->toDateString()])
            ->assertHasFormErrors(['attempted_at']);
        $this->assertSame(0, DeliveryAttempt::count());

        $this->detail($order)
            ->callAction('recordAttempt', data: ['result' => DeliveryAttemptResult::Other->value, 'notes' => 'Calle cerrada por obra', 'attempted_at' => today()->subDay()->toDateString()])
            ->assertHasNoFormErrors();

        $attempt = $order->deliveryAttempts()->sole();
        $this->assertSame(DeliveryAttemptResult::Other, $attempt->result);
        $this->assertTrue($attempt->attempted_at->isSameDay(today()->subDay()));
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);
    }

    public function test_the_results_have_spanish_labels()
    {
        $this->assertSame(
            ['Entregado', 'No estaba', 'Rechazó el pedido', 'Dirección incorrecta', 'Otro'],
            array_map(fn (DeliveryAttemptResult $result) => $result->getLabel(), DeliveryAttemptResult::cases()),
        );
        $this->assertSame(
            ['delivered', 'not_home', 'rejected', 'wrong_address', 'other'],
            array_column(DeliveryAttemptResult::cases(), 'value'),
        );
    }

    // ---- 5. Con 2 intentos fallidos: advertencia y cambio a recoger -------------------------------

    public function test_after_two_failed_attempts_the_order_can_switch_to_pickup_without_changing_the_total()
    {
        $order = $this->order('CP-00001', OrderStatus::Shipped);
        $warning = 'Van 2 intentos de entrega fallidos. Puedes cambiar el pedido a recoger en tienda.';
        $this->panelAs($this->coppersStaff, $this->coppers);

        $this->failedAttempt($order);

        $this->detail($order)->assertActionHidden('switchToPickup')->assertDontSee('intentos de entrega fallidos');
        $this->refuses(fn () => $this->deliveries()->switchToPickup($order, $this->coppersStaff));
        $this->assertSame(DeliveryMethod::Local, $order->fresh()->delivery_method);

        $this->failedAttempt($order, DeliveryAttemptResult::WrongAddress);
        // Reprogramado después del segundo intento: al cambiar a recoger esa fecha ya no aplica.
        $this->deliveries()->schedule($order, today()->addDay(), 'Tarde', $this->coppersStaff);

        $this->detail($order)
            ->assertSee($warning)
            ->assertActionVisible('switchToPickup')
            ->assertActionHasLabel('switchToPickup', 'Cambiar a recoger en tienda')
            ->callAction('switchToPickup');

        $order->refresh();
        $this->assertSame(DeliveryMethod::Pickup, $order->delivery_method);
        $this->assertSame(OrderStatus::Shipped, $order->status);
        $this->assertSame('Listo para recoger', $order->statusLabel());
        $this->assertNull($order->delivery_scheduled_date);
        $this->assertNull($order->delivery_window);

        // Los totales no cambian: el envío cobrado se queda.
        $this->assertSame('1000.00', $order->subtotal);
        $this->assertSame('80.00', $order->shipping_cost);
        $this->assertSame('1080.00', $order->total);

        $row = $order->statusHistories()->get()->last();
        $this->assertSame(OrderStatus::Shipped, $row->from_status);
        $this->assertSame(OrderStatus::Shipped, $row->to_status);
        $this->assertSame('Se cambió la entrega a recoger en tienda después de 2 intentos de entrega fallidos.', $row->note);

        // Ya es para recoger: no hay más acciones de entrega a domicilio, pero los intentos se siguen viendo.
        $this->detail($order)
            ->assertActionHidden('switchToPickup')
            ->assertActionHidden('scheduleDelivery')
            ->assertActionHidden('recordAttempt')
            ->assertDontSee($warning)
            ->assertSee('Dirección incorrecta');
    }

    // ---- Avisos por WhatsApp ---------------------------------------------------------------------

    public function test_the_whatsapp_button_uses_the_delivery_messages()
    {
        $order = $this->order('CP-00001', OrderStatus::Shipped);
        $order->load('store', 'customer');

        // Sin fecha ni intentos: el aviso normal de «en camino».
        $this->assertStringContainsString('ya va en camino', OrderWhatsAppMessage::statusNotice($order));

        $date = today()->addDays(2);
        $this->deliveries()->schedule($order, $date, 'Entre 4 y 6 pm', $this->coppersStaff);
        $this->assertSame(
            "Hola Ana Pérez, programamos la entrega a domicilio de tu pedido CP-00001.\nFecha: ".$date->translatedFormat('l j \d\e F \d\e Y')."\nHorario: Entre 4 y 6 pm\nCopper's",
            OrderWhatsAppMessage::statusNotice($order),
        );

        $this->failedAttempt($order);
        $this->assertSame(
            "Hola Ana Pérez, intentamos entregar tu pedido CP-00001 y no fue posible. ¿Reprogramamos la entrega?\nDinos qué día y a qué hora te queda bien.\nCopper's",
            OrderWhatsAppMessage::statusNotice($order),
        );

        // El botón del panel abre el chat del cliente con ese mensaje.
        $this->panelAs($this->coppersStaff, $this->coppers);
        $this->detail($order)
            ->assertActionVisible('whatsapp')
            ->assertActionHasUrl('whatsapp', 'https://wa.me/527559998877?text='.rawurlencode(OrderWhatsAppMessage::statusNotice($order)));

        $this->deliveries()->recordAttempt($order, DeliveryAttemptResult::Delivered, null, $this->coppersStaff);
        $this->assertSame(
            "Hola Ana Pérez, confirmamos la entrega de tu pedido CP-00001 en tu domicilio.\n¡Gracias por tu compra en Copper's!",
            OrderWhatsAppMessage::statusNotice($order),
        );

        // Un pedido para recoger con el mismo estado conserva sus mensajes de siempre.
        $pickup = $this->order('CP-00002', OrderStatus::Delivered, DeliveryMethod::Pickup)->load('store', 'customer');
        $this->assertStringContainsString('fue entregado', OrderWhatsAppMessage::statusNotice($pickup));
    }

    // ---- 6. El cliente ve la fecha y el aviso de intento fallido, sin notas internas ---------------

    public function test_the_customer_sees_the_date_and_the_failed_attempt_notice_without_internal_notes()
    {
        $order = $this->order('CP-00001', OrderStatus::Shipped, extra: ['internal_notes' => 'Cliente difícil']);
        $date = today()->addDays(2);

        // Sin programar: ningún bloque.
        $this->assertSame(['scheduled' => null, 'failed' => false, 'reschedule_url' => null], $this->props($order)['delivery']);

        $this->deliveries()->schedule($order, $date, 'Entre 4 y 6 pm', $this->coppersStaff);
        $props = $this->props($order);
        $this->assertSame(['date' => $date->translatedFormat('l j \d\e F \d\e Y'), 'window' => 'Entre 4 y 6 pm'], $props['delivery']['scheduled']);
        $this->assertFalse($props['delivery']['failed']);

        $this->failedAttempt($order, DeliveryAttemptResult::Other, 'Perro bravo en la entrada');
        $props = $this->props($order);
        $this->assertNull($props['delivery']['scheduled']);
        $this->assertTrue($props['delivery']['failed']);
        $this->assertSame(
            'https://wa.me/527551111111?text='.rawurlencode('Hola, quiero reprogramar la entrega de mi pedido CP-00001.'),
            $props['delivery']['reschedule_url'],
        );

        // Nada de notas internas, notas de los intentos, anotaciones del historial ni nombres del personal.
        $json = json_encode($props, JSON_UNESCAPED_UNICODE);
        foreach (['Perro bravo', 'Laura Staff', 'Cliente difícil', 'Entrega programada para', 'Anotación', 'internal_notes'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $json);
        }
        $this->assertCount(1, $props['order']['timeline']);

        // Reprogramado: vuelve el bloque de la fecha y se quita el aviso.
        $this->deliveries()->schedule($order, $date, null, $this->coppersStaff);
        $props = $this->props($order);
        $this->assertSame(['date' => $date->translatedFormat('l j \d\e F \d\e Y'), 'window' => null], $props['delivery']['scheduled']);
        $this->assertFalse($props['delivery']['failed']);

        // Ya entregado: ni fecha ni aviso.
        $this->deliveries()->recordAttempt($order, DeliveryAttemptResult::Delivered, null, $this->coppersStaff);
        $this->assertSame(['scheduled' => null, 'failed' => false, 'reschedule_url' => null], $this->props($order)['delivery']);
    }

    // ---- 7. El staff de otra tienda no puede programar ni registrar intentos ----------------------

    public function test_staff_of_another_store_can_not_schedule_or_record_attempts()
    {
        $order = $this->order('CP-00001', OrderStatus::Shipped);
        $this->failedAttempt($order);
        $this->failedAttempt($order);

        $messages = [
            $this->refuses(fn () => $this->deliveries()->schedule($order, today()->addDay(), 'Tarde', $this->g5Staff)),
            $this->refuses(fn () => $this->deliveries()->recordAttempt($order, DeliveryAttemptResult::Delivered, null, $this->g5Staff)),
            $this->refuses(fn () => $this->deliveries()->recordAttempt($order, DeliveryAttemptResult::NotHome, null, $this->g5Staff)),
            $this->refuses(fn () => $this->deliveries()->switchToPickup($order, $this->g5Staff)),
        ];

        $this->assertSame(array_fill(0, 4, 'El pedido CP-00001 es de otra tienda.'), $messages);

        $order->refresh();
        $this->assertNull($order->delivery_scheduled_date);
        $this->assertSame(OrderStatus::Shipped, $order->status);
        $this->assertSame(DeliveryMethod::Local, $order->delivery_method);
        $this->assertCount(2, $order->deliveryAttempts()->get());

        // En el panel ni siquiera llega al pedido: ni en la tienda ajena ni pidiéndolo dentro de la suya.
        $this->actingAs($this->g5Staff, 'web');
        $this->get('/admin/coppers/orders/'.$order->id)->assertNotFound();
        $this->get('/admin/g5/orders/'.$order->id)->assertNotFound();

        // El staff de la tienda y el owner sí pueden.
        $this->deliveries()->schedule($order, today()->addDay(), 'Tarde', $this->coppersStaff);
        $this->deliveries()->schedule($order, today()->addDays(2), 'Tarde', $this->owner);
        $this->assertTrue($order->fresh()->delivery_scheduled_date->isSameDay(today()->addDays(2)));
    }

    // ---- 8. Pestañas del widget ------------------------------------------------------------------

    public function test_the_widget_tabs_list_the_right_orders()
    {
        $today = ['delivery_scheduled_date' => today(), 'delivery_window' => 'Entre 4 y 6 pm'];

        $todayPaid = $this->order('CP-00001', OrderStatus::Paid, extra: $today);
        $todayShipped = $this->order('CP-00002', OrderStatus::Shipped, extra: $today);
        $tomorrow = $this->order('CP-00003', OrderStatus::Paid, extra: ['delivery_scheduled_date' => today()->addDay()]);
        $unscheduled = $this->order('CP-00004', OrderStatus::Paid);
        $reserved = $this->order('CP-00005', OrderStatus::Reserved);
        $pickupPaid = $this->order('CP-00006', OrderStatus::Paid, DeliveryMethod::Pickup);
        $nationalPaid = $this->order('CP-00007', OrderStatus::Paid, DeliveryMethod::National);
        $shippedNoAttempts = $this->order('CP-00008', OrderStatus::Shipped);

        $failed = $this->order('CP-00009', OrderStatus::Shipped, extra: $today);
        $this->failedAttempt($failed);

        $rescheduled = $this->order('CP-00010', OrderStatus::Shipped);
        $this->failedAttempt($rescheduled);
        $this->deliveries()->schedule($rescheduled, today()->addDays(3), 'Tarde', $this->coppersStaff);

        $delivered = $this->order('CP-00011', OrderStatus::Shipped, extra: $today);
        $this->failedAttempt($delivered);
        $this->deliveries()->schedule($delivered, today(), 'Tarde', $this->coppersStaff);
        $this->deliveries()->recordAttempt($delivered, DeliveryAttemptResult::Delivered, null, $this->coppersStaff);

        // Lo mismo en la otra tienda: nunca debe aparecer.
        $otherToday = $this->order('G5-00001', OrderStatus::Paid, store: $this->g5, extra: $today);
        $otherUnscheduled = $this->order('G5-00002', OrderStatus::Paid, store: $this->g5);
        $otherFailed = $this->order('G5-00003', OrderStatus::Shipped, store: $this->g5);
        $this->failedAttempt($otherFailed, actor: $this->g5Staff);

        $all = collect([$todayPaid, $todayShipped, $tomorrow, $unscheduled, $reserved, $pickupPaid, $nationalPaid, $shippedNoAttempts, $failed, $rescheduled, $delivered, $otherToday, $otherUnscheduled, $otherFailed]);
        $except = fn (Order ...$shown) => $all->reject(fn (Order $order) => collect($shown)->contains(fn (Order $one) => $one->is($order)))->all();

        $this->panelAs($this->coppersStaff, $this->coppers);

        Livewire::test(HomeDeliveries::class)
            ->assertSee('Entregas a domicilio')
            ->assertSee(['Hoy', 'Por programar', 'Por reprogramar'])
            ->assertSet('activeTab', 'today')
            ->assertCanSeeTableRecords([$todayPaid, $todayShipped], inOrder: true)
            ->assertCanNotSeeTableRecords($except($todayPaid, $todayShipped))
            // Cada fila: número, cliente, teléfono, dirección y enlace al pedido.
            ->assertTableColumnStateSet('number', 'CP-00001', $todayPaid)
            ->assertTableColumnStateSet('customer.name', 'Ana Pérez', $todayPaid)
            ->assertTableColumnStateSet('contact_phone', '7559998877', $todayPaid)
            ->assertTableColumnStateSet('address', 'Av. del Mar 12, Centro', $todayPaid)
            ->assertSee('Entre 4 y 6 pm')
            ->assertSee('/admin/coppers/orders/'.$todayPaid->id, escape: false)
            ->set('activeTab', 'unscheduled')
            ->assertCanSeeTableRecords([$unscheduled])
            ->assertCanNotSeeTableRecords($except($unscheduled))
            ->set('activeTab', 'reschedule')
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords($except($failed));

        // La otra tienda ve solo lo suyo.
        $this->panelAs($this->g5Staff, $this->g5);

        Livewire::test(HomeDeliveries::class)
            ->assertCanSeeTableRecords([$otherToday])
            ->assertCanNotSeeTableRecords($except($otherToday))
            ->set('activeTab', 'unscheduled')
            ->assertCanSeeTableRecords([$otherUnscheduled])
            ->assertCanNotSeeTableRecords($except($otherUnscheduled))
            ->set('activeTab', 'reschedule')
            ->assertCanSeeTableRecords([$otherFailed])
            ->assertCanNotSeeTableRecords($except($otherFailed));
    }

    public function test_the_dashboard_renders_the_home_deliveries_widget()
    {
        $this->actingAs($this->coppersStaff, 'web');

        $this->get('/admin/coppers')->assertOk()->assertSeeLivewire(HomeDeliveries::class);
    }
}
