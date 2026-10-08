<?php

namespace Tests\Feature;

use App\Enums\CancelledBy;
use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\SizeType;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderStatusService;
use App\Support\OrderWhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class OrderStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private Store $coppers;

    private Customer $ana;

    private User $staff;

    private OrderStatusService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers', 'whatsapp_phone' => '7550000000']);
        $this->ana = Customer::factory()->create(['name' => 'Ana Pérez', 'phone' => '755 123 4567']);
        $this->staff = User::factory()->create(['name' => 'Laura Staff', 'role' => UserRole::Staff]);
        $this->staff->stores()->attach($this->coppers);
        $this->service = app(OrderStatusService::class);
    }

    private function order(OrderStatus $status = OrderStatus::Reserved, DeliveryMethod $method = DeliveryMethod::Pickup, array $extra = []): Order
    {
        static $sequence = 0;

        return Order::create([
            'store_id' => $this->coppers->id,
            'customer_id' => $this->ana->id,
            'number' => sprintf('CP-%05d', ++$sequence),
            'channel' => OrderChannel::Web,
            'status' => $status,
            'delivery_method' => $method,
            'subtotal' => 1500,
            'total' => 1500,
            ...$extra,
        ]);
    }

    // ---- 1. Transiciones ---------------------------------------------------------------------------

    public function test_valid_transitions_pass()
    {
        $order = $this->order();

        $this->service->transition($order, OrderStatus::Paid, $this->staff);
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);

        $this->service->transition($order, OrderStatus::Shipped, $this->staff);
        $this->assertSame(OrderStatus::Shipped, $order->fresh()->status);

        $this->service->transition($order, OrderStatus::Delivered, $this->staff);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);

        // Y las dos cancelaciones permitidas.
        $reserved = $this->order();
        $this->service->transition($reserved, OrderStatus::Cancelled, $this->staff);
        $this->assertSame(OrderStatus::Cancelled, $reserved->fresh()->status);

        $paid = $this->order(OrderStatus::Paid);
        $this->service->transition($paid, OrderStatus::Cancelled, $this->staff);
        $this->assertSame(OrderStatus::Cancelled, $paid->fresh()->status);
    }

    public function test_reserved_to_delivered_and_delivered_to_paid_fail()
    {
        $reserved = $this->order(OrderStatus::Reserved);
        $delivered = $this->order(OrderStatus::Delivered);

        foreach ([[$reserved, OrderStatus::Delivered], [$delivered, OrderStatus::Paid]] as [$order, $to]) {
            try {
                $this->service->transition($order, $to, $this->staff);
                $this->fail("Se permitió {$order->status->value} → {$to->value}.");
            } catch (InvalidOrderTransitionException $exception) {
                $this->assertStringContainsString($order->number, $exception->getMessage());
            }
        }

        // Nada cambió ni quedó en el historial.
        $this->assertSame(OrderStatus::Reserved, $reserved->fresh()->status);
        $this->assertSame(OrderStatus::Delivered, $delivered->fresh()->status);
        $this->assertNull($reserved->fresh()->delivered_at);
        $this->assertSame(0, OrderStatusHistory::count());
    }

    public function test_every_transition_outside_the_allowed_ones_is_rejected()
    {
        $allowed = [
            'apartado' => ['pagado', 'cancelado'],
            'pagado' => ['enviado', 'cancelado'],
            'enviado' => ['entregado'],
            'entregado' => [],
            'cancelado' => [],
        ];

        foreach (OrderStatus::cases() as $from) {
            foreach (OrderStatus::cases() as $to) {
                $order = $this->order($from);
                $shouldPass = in_array($to->value, $allowed[$from->value], true);

                try {
                    $this->service->transition($order, $to, $this->staff);
                    $passed = true;
                } catch (InvalidOrderTransitionException) {
                    $passed = false;
                }

                $this->assertSame($shouldPass, $passed, "{$from->value} → {$to->value}");
                $this->assertSame($shouldPass ? $to : $from, $order->fresh()->status, "{$from->value} → {$to->value}");
            }
        }
    }

    public function test_the_status_can_not_be_changed_outside_the_service()
    {
        $order = $this->order();

        try {
            $order->update(['status' => OrderStatus::Paid]);
            $this->fail('Se cambió el estado sin pasar por OrderStatusService.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('OrderStatusService', $exception->getMessage());
        }

        $this->assertSame(OrderStatus::Reserved, $order->fresh()->status);

        // Lo demás del pedido sí se puede editar.
        $order->refresh()->update(['internal_notes' => 'Llamar antes']);
        $this->assertSame('Llamar antes', $order->fresh()->internal_notes);
    }

    // ---- 2. Historial -------------------------------------------------------------------------------

    public function test_each_transition_creates_a_history_row_with_the_user()
    {
        $order = $this->order();
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->service->transition($order, OrderStatus::Paid, $this->staff, '  Transferencia BBVA  ');
        $this->service->transition($order, OrderStatus::Shipped, $owner);
        $this->service->transition($order, OrderStatus::Delivered, $this->staff, 'Lo recogió su hermana');

        $history = $order->statusHistories()->get();

        $this->assertCount(3, $history);
        $this->assertSame(
            [
                ['apartado', 'pagado', $this->staff->id, 'Transferencia BBVA'],
                ['pagado', 'enviado', $owner->id, null],
                ['enviado', 'entregado', $this->staff->id, 'Lo recogió su hermana'],
            ],
            $history->map(fn (OrderStatusHistory $row) => [$row->from_status->value, $row->to_status->value, $row->user_id, $row->note])->all(),
        );
        $this->assertTrue($history->every(fn (OrderStatusHistory $row) => $row->customer_id === null && $row->created_at !== null));
        $this->assertSame('Laura Staff', $history[0]->actorLabel());
    }

    public function test_the_history_tells_customers_and_the_system_apart()
    {
        $byCustomer = $this->order();
        $bySystem = $this->order();

        $this->requestingStore($this->coppers);
        $this->service->transition($byCustomer, OrderStatus::Cancelled, $this->ana, 'Ya no lo quiero');
        $this->service->transition($bySystem, OrderStatus::Cancelled, null, 'Venció el apartado');

        $customerRow = $byCustomer->statusHistories()->sole();
        $systemRow = $bySystem->statusHistories()->sole();

        $this->assertSame([null, $this->ana->id, 'Ana Pérez (cliente)'], [$customerRow->user_id, $customerRow->customer_id, $customerRow->actorLabel()]);
        $this->assertSame([null, null, 'Sistema'], [$systemRow->user_id, $systemRow->customer_id, $systemRow->actorLabel()]);

        $this->assertSame(CancelledBy::Customer, $byCustomer->fresh()->cancelled_by);
        $this->assertSame('Ya no lo quiero', $byCustomer->fresh()->cancel_reason);
        $this->assertNotNull($byCustomer->fresh()->cancelled_at);
        $this->assertSame(CancelledBy::System, $bySystem->fresh()->cancelled_by);

        $byStaff = $this->order();
        $this->service->transition($byStaff, OrderStatus::Cancelled, $this->staff);
        $this->assertSame(CancelledBy::Admin, $byStaff->fresh()->cancelled_by);
    }

    public function test_the_initial_history_has_no_previous_status()
    {
        $order = $this->order();

        $row = $this->service->recordInitial($order, $this->ana);

        $this->assertNull($row->from_status);
        $this->assertSame(OrderStatus::Reserved, $row->to_status);
        $this->assertSame($this->ana->id, $row->customer_id);
    }

    public function test_placing_an_order_records_the_initial_history()
    {
        $this->withoutVite();
        $category = Category::create(['store_id' => $this->coppers->id, 'name' => 'General', 'slug' => 'general', 'size_type' => SizeType::OneSize]);
        $product = Product::create(['store_id' => $this->coppers->id, 'category_id' => $category->id, 'name' => 'Camisa', 'slug' => 'camisa', 'price' => 500]);
        $variant = $product->variants()->create(['size' => 'M', 'stock' => 5]);

        $this->post('http://coppers.localhost/carrito', ['variant_id' => $variant->id]);
        $this->actingAs($this->ana, 'customer')->post('http://coppers.localhost/checkout', ['delivery_method' => 'recoger', 'phone' => '7551234567']);

        $order = Order::withoutGlobalScopes()->sole();
        $row = $order->statusHistories()->sole();

        $this->assertNull($row->from_status);
        $this->assertSame(OrderStatus::Reserved, $row->to_status);
        $this->assertSame($this->ana->id, $row->customer_id);
        $this->assertNull($row->user_id);
    }

    // ---- Datos de cada paso ---------------------------------------------------------------------------

    public function test_marking_paid_sets_the_date_and_the_reference()
    {
        $this->travelTo('2026-10-06 12:00:00');
        $order = $this->order();

        $this->service->transition($order, OrderStatus::Paid, $this->staff, data: ['payment_reference' => ' MBAN01002610060012345678 ']);

        $this->assertTrue($order->paid_at->equalTo(now()));
        $this->assertSame('MBAN01002610060012345678', $order->payment_reference);
    }

    public function test_marking_delivered_sets_the_delivery_date()
    {
        $this->travelTo('2026-10-08 18:30:00');
        $order = $this->order(OrderStatus::Shipped);

        $this->service->transition($order, OrderStatus::Delivered, $this->staff);

        $this->assertTrue($order->delivered_at->equalTo(now()));
    }

    // ---- 3. Envío nacional -----------------------------------------------------------------------------

    public function test_a_national_order_can_not_be_marked_shipped_without_tracking()
    {
        $order = $this->order(OrderStatus::Paid, DeliveryMethod::National);

        foreach ([[], ['tracking_carrier' => 'Estafeta'], ['tracking_number' => '123456'], ['tracking_carrier' => ' ', 'tracking_number' => '']] as $data) {
            try {
                $this->service->transition($order, OrderStatus::Shipped, $this->staff, data: $data);
                $this->fail('Se marcó enviado sin paquetería y guía.');
            } catch (InvalidOrderTransitionException $exception) {
                $this->assertStringContainsString('guía', $exception->getMessage());
            }
        }

        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNull($order->tracking_carrier);
        $this->assertNull($order->tracking_number);
        $this->assertSame(0, OrderStatusHistory::count());

        $this->service->transition($order, OrderStatus::Shipped, $this->staff, data: ['tracking_carrier' => ' Estafeta ', 'tracking_number' => ' 123456 ']);

        $this->assertSame(OrderStatus::Shipped, $order->status);
        $this->assertSame(['Estafeta', '123456'], [$order->tracking_carrier, $order->tracking_number]);
    }

    public function test_pickup_and_local_orders_do_not_need_tracking()
    {
        foreach ([DeliveryMethod::Pickup, DeliveryMethod::Local] as $method) {
            $order = $this->order(OrderStatus::Paid, $method);

            $this->service->transition($order, OrderStatus::Shipped, $this->staff);

            $this->assertSame(OrderStatus::Shipped, $order->status);
        }
    }

    // ---- 5. Etiquetas -----------------------------------------------------------------------------------

    public function test_shipped_reads_ready_for_pickup_or_on_its_way_depending_on_the_delivery_method()
    {
        $this->assertSame('Listo para recoger', OrderStatus::Shipped->labelFor(DeliveryMethod::Pickup));
        $this->assertSame('En camino', OrderStatus::Shipped->labelFor(DeliveryMethod::Local));
        $this->assertSame('En camino', OrderStatus::Shipped->labelFor(DeliveryMethod::National));
        $this->assertSame('Enviado', OrderStatus::Shipped->labelFor(null));

        // Los demás estados se dicen igual con cualquier entrega.
        foreach (DeliveryMethod::cases() as $method) {
            $this->assertSame('Apartado', OrderStatus::Reserved->labelFor($method));
            $this->assertSame('Pagado', OrderStatus::Paid->labelFor($method));
            $this->assertSame('Entregado', OrderStatus::Delivered->labelFor($method));
            $this->assertSame('Cancelado', OrderStatus::Cancelled->labelFor($method));
        }

        $this->assertSame('Listo para recoger', $this->order(OrderStatus::Shipped, DeliveryMethod::Pickup)->statusLabel());
        $this->assertSame('En camino', $this->order(OrderStatus::Shipped, DeliveryMethod::National)->statusLabel());
        $this->assertSame('Pagado', $this->order(OrderStatus::Paid, DeliveryMethod::Pickup)->statusLabel());
    }

    // ---- 6. WhatsApp al cliente ----------------------------------------------------------------------

    private function text(string $url): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query['text'];
    }

    public function test_the_whatsapp_notice_carries_the_order_number_and_goes_to_the_customers_phone()
    {
        $order = $this->order(OrderStatus::Paid, extra: ['total' => 1500]);

        $url = OrderWhatsAppMessage::statusNoticeUrl($order);

        // Al teléfono del cliente (limpio y con 52), no al de la tienda.
        $this->assertStringStartsWith('https://wa.me/527551234567?text=', $url);
        $this->assertStringNotContainsString('7550000000', $url);
        $this->assertSame(
            "Hola Ana Pérez, recibimos el pago de tu pedido {$order->number} por \$1,500.00. ¡Gracias!\nTe avisamos en cuanto esté listo para recoger.\nCopper's",
            $this->text($url),
        );
    }

    public function test_the_notice_changes_with_the_status_and_the_delivery_method()
    {
        $pickup = $this->order(OrderStatus::Shipped, DeliveryMethod::Pickup);
        $national = $this->order(OrderStatus::Shipped, DeliveryMethod::National, ['tracking_carrier' => 'Estafeta', 'tracking_number' => 'ABC123']);
        $local = $this->order(OrderStatus::Shipped, DeliveryMethod::Local);
        $delivered = $this->order(OrderStatus::Delivered);
        $paidLocal = $this->order(OrderStatus::Paid, DeliveryMethod::Local);

        $this->assertSame(
            "Hola Ana Pérez, tu pedido {$pickup->number} ya está listo para recoger en Copper's.\nAl pasar por él menciona tu número de pedido.",
            OrderWhatsAppMessage::statusNotice($pickup),
        );
        $this->assertSame(
            "Hola Ana Pérez, tu pedido {$national->number} ya va en camino.\nPaquetería: Estafeta\nGuía: ABC123\nCopper's",
            OrderWhatsAppMessage::statusNotice($national),
        );
        $this->assertSame("Hola Ana Pérez, tu pedido {$local->number} ya va en camino.\nCopper's", OrderWhatsAppMessage::statusNotice($local));
        $this->assertSame(
            "Hola Ana Pérez, tu pedido {$delivered->number} fue entregado.\n¡Gracias por tu compra en Copper's!",
            OrderWhatsAppMessage::statusNotice($delivered),
        );
        $this->assertStringContainsString('Te avisamos en cuanto vaya en camino.', OrderWhatsAppMessage::statusNotice($paidLocal));
    }

    public function test_reserved_and_cancelled_orders_have_no_notice()
    {
        $this->assertNull(OrderWhatsAppMessage::statusNotice($this->order(OrderStatus::Reserved)));
        $this->assertNull(OrderWhatsAppMessage::statusNoticeUrl($this->order(OrderStatus::Reserved)));
        $this->assertNull(OrderWhatsAppMessage::statusNoticeUrl($this->order(OrderStatus::Cancelled)));
    }

    public function test_the_notice_prefers_the_phone_left_for_the_delivery_and_needs_one()
    {
        $withAddress = $this->order(OrderStatus::Paid, DeliveryMethod::Local, ['shipping_address' => ['street' => 'Calle 1', 'phone' => '(755) 999-8888']]);

        $this->assertStringStartsWith('https://wa.me/527559998888?text=', OrderWhatsAppMessage::statusNoticeUrl($withAddress));

        $this->ana->update(['phone' => null]);

        $this->assertNull(OrderWhatsAppMessage::statusNoticeUrl($this->order(OrderStatus::Paid)));

        // Venta de mostrador sin cliente: no hay a quién avisar.
        $counter = $this->order(OrderStatus::Paid, extra: ['customer_id' => null, 'channel' => OrderChannel::Counter]);
        $this->assertNull(OrderWhatsAppMessage::statusNoticeUrl($counter));
    }
}
