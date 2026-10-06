<?php

namespace Tests\Feature;

use App\Enums\CancelledBy;
use App\Enums\CouponType;
use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\SizeType;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\CancellationRequestService;
use App\Services\OrderStatusService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

class MyOrdersTest extends TestCase
{
    use RefreshDatabase;

    private const COPPERS = 'http://coppers.localhost';

    private const G5 = 'http://g5.localhost';

    private Store $coppers;

    private Store $g5;

    private Customer $ana;

    private Customer $luis;

    private User $owner;

    private User $coppersStaff;

    private User $g5Staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers', 'whatsapp_phone' => '755 111 1111', 'bank_info' => 'BBVA CLABE 0123']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5', 'whatsapp_phone' => '755 222 2222']);
        $this->ana = Customer::factory()->create(['name' => 'Ana Pérez', 'phone' => '7551234567']);
        $this->luis = Customer::factory()->create(['name' => 'Luis Gómez']);
        $this->owner = User::factory()->create(['name' => 'Dueña Olga', 'role' => UserRole::Owner]);
        $this->coppersStaff = User::factory()->create(['name' => 'Laura Staff', 'role' => UserRole::Staff]);
        $this->coppersStaff->stores()->attach($this->coppers->id);
        $this->g5Staff = User::factory()->create(['name' => 'Gabriel Staff', 'role' => UserRole::Staff]);
        $this->g5Staff->stores()->attach($this->g5->id);
    }

    private function variant(Store $store, string $name, int $stock): ProductVariant
    {
        $category = Category::firstOrCreate(
            ['store_id' => $store->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        return $category->products()
            ->create(['store_id' => $store->id, 'name' => $name, 'slug' => str($name)->slug(), 'price' => 500])
            ->variants()->create(['size' => 'M', 'stock' => $stock]);
    }

    /** @param list<array{0: ProductVariant, 1: int, 2?: bool}> $lines talla, cantidad, sobre pedido */
    private function order(Store $store, Customer $customer, string $number, OrderStatus $status = OrderStatus::Reserved, array $lines = [], array $extra = []): Order
    {
        $order = Order::create([
            'store_id' => $store->id,
            'customer_id' => $customer->id,
            'number' => $number,
            'channel' => OrderChannel::Web,
            'status' => $status,
            'delivery_method' => DeliveryMethod::Pickup,
            'subtotal' => 1000,
            'total' => 1000,
            'expires_at' => now()->addHours(48),
            'paid_at' => in_array($status, [OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Delivered], true) ? now() : null,
            ...$extra,
        ]);

        foreach ($lines as $line) {
            $order->items()->create([
                'product_variant_id' => $line[0]->id,
                'product_name' => $line[0]->product()->withoutGlobalScopes()->first()->name,
                'size' => $line[0]->size,
                'unit_price' => 500,
                'quantity' => $line[1],
                'is_backorder' => $line[2] ?? false,
            ]);
        }

        app(OrderStatusService::class)->recordInitial($order, $customer);

        return $order;
    }

    /** Avisos de «Solicitud de cancelación» en la campana de un usuario (sin contar los de «Pedido nuevo»). */
    private function requestNotices(User $user): Collection
    {
        return $user->notifications()->get()->filter(fn ($notification) => $notification->data['title'] === 'Solicitud de cancelación')->values();
    }

    private function asAna(): static
    {
        return $this->actingAs($this->ana, 'customer');
    }

    /** Props de una página de «Mis pedidos». */
    private function props(string $url, ?Customer $customer = null): array
    {
        $props = [];

        $this->actingAs($customer ?? $this->ana, 'customer')->get($url)->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    // ---- 1. Solo sus pedidos de esta tienda ------------------------------------------------------

    public function test_the_customer_only_sees_their_orders_of_the_current_store()
    {
        $this->order($this->coppers, $this->ana, 'CP-00001')->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->order($this->coppers, $this->ana, 'CP-00002', OrderStatus::Paid)->forceFill(['created_at' => now()->subDay()])->save();
        $this->order($this->coppers, $this->luis, 'CP-00003');
        $this->order($this->g5, $this->ana, 'G5-00001');

        $coppers = $this->props(self::COPPERS.'/mi-cuenta/pedidos');

        // Más recientes primero, sin el de Luis ni el de la otra tienda.
        $this->assertSame(['CP-00002', 'CP-00001'], array_column($coppers['orders'], 'number'));
        $this->assertSame(2, $coppers['pagination']['total']);

        $g5 = $this->props(self::G5.'/mi-cuenta/pedidos');
        $this->assertSame(['G5-00001'], array_column($g5['orders'], 'number'));

        $this->assertSame(['CP-00003'], array_column($this->props(self::COPPERS.'/mi-cuenta/pedidos', $this->luis)['orders'], 'number'));
        $this->assertSame([], $this->props(self::G5.'/mi-cuenta/pedidos', $this->luis)['orders']);
    }

    public function test_the_list_shows_number_date_total_and_status_in_spanish()
    {
        $this->travelTo('2026-10-05 10:30:00');
        $this->order($this->coppers, $this->ana, 'CP-00001', OrderStatus::Shipped, extra: ['total' => 1700.5]);
        $this->order($this->coppers, $this->ana, 'CP-00002', OrderStatus::Shipped, extra: ['delivery_method' => DeliveryMethod::National]);
        $this->order($this->coppers, $this->ana, 'CP-00003', OrderStatus::Cancelled);

        $orders = collect($this->props(self::COPPERS.'/mi-cuenta/pedidos')['orders'])->keyBy('number');

        $this->assertSame(
            ['number' => 'CP-00001', 'date' => '5 de octubre de 2026', 'total' => '1700.50', 'status' => 'enviado', 'status_label' => 'Listo para recoger', 'expiring_label' => null],
            $orders['CP-00001'],
        );
        $this->assertSame('En camino', $orders['CP-00002']['status_label']);
        $this->assertSame(['cancelado', 'Cancelado'], [$orders['CP-00003']['status'], $orders['CP-00003']['status_label']]);
    }

    public function test_the_list_is_paginated_by_ten()
    {
        foreach (range(1, 23) as $i) {
            $this->order($this->coppers, $this->ana, sprintf('CP-%05d', $i))->forceFill(['created_at' => now()->subMinutes(100 - $i)])->save();
        }

        $first = $this->props(self::COPPERS.'/mi-cuenta/pedidos');

        $this->assertCount(10, $first['orders']);
        $this->assertSame('CP-00023', $first['orders'][0]['number']);
        $this->assertSame([1, 3, 23, null], [$first['pagination']['current'], $first['pagination']['last'], $first['pagination']['total'], $first['pagination']['prev_url']]);
        $this->assertSame(self::COPPERS.'/mi-cuenta/pedidos?pagina=2', $first['pagination']['next_url']);

        $last = $this->props(self::COPPERS.'/mi-cuenta/pedidos?pagina=3');

        $this->assertSame(['CP-00003', 'CP-00002', 'CP-00001'], array_column($last['orders'], 'number'));
        $this->assertNull($last['pagination']['next_url']);
    }

    public function test_an_empty_list_and_guests()
    {
        $props = $this->props(self::COPPERS.'/mi-cuenta/pedidos');

        $this->assertSame([], $props['orders']);
        $this->assertSame(0, $props['pagination']['total']);

        auth('customer')->logout();

        $this->get(self::COPPERS.'/mi-cuenta/pedidos')->assertRedirect(self::COPPERS.'/login');
        $this->get(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')->assertRedirect(self::COPPERS.'/login');
        $this->post(self::COPPERS.'/mi-cuenta/pedidos/CP-00001/cancelar')->assertRedirect(self::COPPERS.'/login');
    }

    // ---- Detalle -------------------------------------------------------------------------------

    public function test_the_detail_shows_lines_with_photo_totals_coupon_delivery_and_tracking()
    {
        $variant = $this->variant($this->coppers, 'Camisa Oxford', 3);
        ProductImage::create(['product_id' => $variant->product_id, 'path' => 'products/camisa.jpg', 'position' => 0]);
        $coupon = Coupon::create(['store_id' => $this->coppers->id, 'code' => 'VERANO', 'type' => CouponType::Percent, 'value' => 10, 'used_count' => 1]);
        $this->order($this->coppers, $this->ana, 'CP-00001', OrderStatus::Shipped, [[$variant, 2], [$variant, 1, true]], [
            'delivery_method' => DeliveryMethod::National,
            'shipping_address' => ['street' => 'Calle Hidalgo 5', 'neighborhood' => 'Centro', 'city' => 'Zihuatanejo', 'state' => 'Guerrero', 'zip' => '40880', 'name' => 'Ana Pérez', 'phone' => '7551234567'],
            'subtotal' => 1500, 'discount' => 150, 'shipping_cost' => 250, 'total' => 1600, 'coupon_id' => $coupon->id,
            'tracking_carrier' => 'Estafeta', 'tracking_number' => 'ABC123', 'notes' => 'Tocar el timbre',
        ]);

        $props = $this->props(self::COPPERS.'/mi-cuenta/pedidos/CP-00001');
        $order = $props['order'];

        $this->assertSame(['CP-00001', 'enviado', 'En camino', 'Envío nacional'], [$order['number'], $order['status'], $order['status_label'], $order['delivery_label']]);
        $this->assertSame(['1500.00', '150.00', '250.00', '1600.00', 'VERANO'], [$order['subtotal'], $order['discount'], $order['shipping_cost'], $order['total'], $order['coupon']]);
        $this->assertSame('Calle Hidalgo 5', $order['shipping_address']['street']);
        $this->assertSame(['carrier' => 'Estafeta', 'number' => 'ABC123'], $order['tracking']);
        $this->assertSame('Tocar el timbre', $order['notes']);

        $this->assertCount(2, $order['items']);
        $this->assertSame(['Camisa Oxford', 'M', 2, '500.00', '1000.00', false, '/storage/products/camisa.jpg'], [
            $order['items'][0]['name'], $order['items'][0]['size'], $order['items'][0]['quantity'], $order['items'][0]['unit_price'],
            $order['items'][0]['line_total'], $order['items'][0]['is_backorder'], $order['items'][0]['image_url'],
        ]);
        $this->assertTrue($order['items'][1]['is_backorder']);

        // Ya no está apartado: sin datos de pago ni comprobante, y sin botones de cancelar.
        $this->assertNull($props['bankInfo']);
        $this->assertNull($order['expires_at_text']);
        $this->assertNull($props['whatsapp']['receipt_url']);
        $this->assertSame(['cancel' => false, 'request_cancellation' => false], $props['can']);
    }

    public function test_a_reserved_order_shows_the_deadline_the_bank_data_and_the_receipt_button()
    {
        $this->travelTo('2026-10-05 10:30:00');
        $this->order($this->coppers, $this->ana, 'CP-00001');

        $props = $this->props(self::COPPERS.'/mi-cuenta/pedidos/CP-00001');

        $this->assertSame('7 de octubre de 2026, 10:30', $props['order']['expires_at_text']);
        $this->assertSame('BBVA CLABE 0123', $props['bankInfo']);
        $this->assertStringStartsWith('https://wa.me/527551111111?text=', $props['whatsapp']['receipt_url']);
        $this->assertStringContainsString('CP-00001', rawurldecode($props['whatsapp']['receipt_url']));
        $this->assertSame(['cancel' => true, 'request_cancellation' => false], $props['can']);
        $this->assertFalse($props['cancellationRequested']);
        $this->assertNull($props['order']['tracking']);
    }

    // ---- 5. El pedido de otro ------------------------------------------------------------------

    public function test_a_customer_can_not_see_or_cancel_another_customers_order()
    {
        $variant = $this->variant($this->coppers, 'Camisa', 3);
        $reserved = $this->order($this->coppers, $this->luis, 'CP-00001', OrderStatus::Reserved, [[$variant, 2]]);
        $paid = $this->order($this->coppers, $this->luis, 'CP-00002', OrderStatus::Paid, [[$variant, 1]]);

        $this->asAna()->get(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')->assertNotFound();
        $this->asAna()->post(self::COPPERS.'/mi-cuenta/pedidos/CP-00001/cancelar', ['reason' => 'No es mío'])->assertNotFound();
        $this->asAna()->post(self::COPPERS.'/mi-cuenta/pedidos/CP-00002/solicitar-cancelacion', ['note' => 'No es mío'])->assertNotFound();
        $this->asAna()->get(self::COPPERS.'/mi-cuenta/pedidos/NO-EXISTE')->assertNotFound();

        $this->assertSame(OrderStatus::Reserved, $reserved->fresh()->status);
        $this->assertNull($paid->fresh()->cancellation_requested_at);
        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, $this->requestNotices($this->owner)->count());

        // Su dueño sí lo ve.
        $this->actingAs($this->luis, 'customer')->get(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')->assertOk();
    }

    public function test_an_order_is_not_reachable_from_the_other_stores_domain()
    {
        $variant = $this->variant($this->coppers, 'Camisa', 3);
        $order = $this->order($this->coppers, $this->ana, 'CP-00001', OrderStatus::Reserved, [[$variant, 2]]);

        $this->asAna()->get(self::G5.'/mi-cuenta/pedidos/CP-00001')->assertNotFound();
        $this->asAna()->post(self::G5.'/mi-cuenta/pedidos/CP-00001/cancelar')->assertNotFound();

        $this->assertSame(OrderStatus::Reserved, $order->fresh()->status);
        $this->assertSame(3, $variant->fresh()->stock);
    }

    // ---- 2. Cancelar un apartado ---------------------------------------------------------------

    public function test_cancelling_a_reserved_order_returns_the_stock()
    {
        $variant = $this->variant($this->coppers, 'Camisa', 3);
        $backorder = $this->variant($this->coppers, 'Pantalón', 0);
        $coupon = Coupon::create(['store_id' => $this->coppers->id, 'code' => 'VERANO', 'type' => CouponType::Percent, 'value' => 10, 'used_count' => 1]);
        $order = $this->order($this->coppers, $this->ana, 'CP-00001', OrderStatus::Reserved, [[$variant, 2], [$backorder, 1, true]], ['coupon_id' => $coupon->id]);

        $this->asAna()->post(self::COPPERS.'/mi-cuenta/pedidos/CP-00001/cancelar', ['reason' => '  Me equivoqué de talla  '])
            ->assertRedirect(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')
            ->assertSessionHas('status', 'Cancelamos tu pedido.')
            ->assertSessionHasNoErrors();

        $order->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(CancelledBy::Customer, $order->cancelled_by);
        $this->assertSame('Me equivoqué de talla', $order->cancel_reason);
        $this->assertNotNull($order->cancelled_at);

        // Regresan las piezas de la línea normal; la de sobre pedido no toca stock.
        $this->assertSame(5, $variant->fresh()->stock);
        $this->assertSame(0, $backorder->fresh()->stock);

        $movement = StockMovement::sole();
        $this->assertSame([StockMovementType::Cancellation, 2, $order->id, null], [$movement->type, $movement->quantity, $movement->order_id, $movement->user_id]);

        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertSame($this->ana->id, $order->statusHistories()->get()->last()->customer_id);

        // Ya cancelado: sin botones.
        $props = $this->props(self::COPPERS.'/mi-cuenta/pedidos/CP-00001');
        $this->assertSame(['cancel' => false, 'request_cancellation' => false], $props['can']);
        $this->assertSame('Cancelado', $props['order']['status_label']);
    }

    public function test_the_reason_is_optional_when_cancelling()
    {
        $variant = $this->variant($this->coppers, 'Camisa', 3);
        $order = $this->order($this->coppers, $this->ana, 'CP-00001', OrderStatus::Reserved, [[$variant, 1]]);

        $this->asAna()->post(self::COPPERS.'/mi-cuenta/pedidos/CP-00001/cancelar')->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertNull($order->fresh()->cancel_reason);
        $this->assertSame(4, $variant->fresh()->stock);
    }

    // ---- 7. Doble clic -------------------------------------------------------------------------

    public function test_a_double_click_on_cancel_does_not_return_the_stock_twice()
    {
        $variant = $this->variant($this->coppers, 'Camisa', 3);
        $coupon = Coupon::create(['store_id' => $this->coppers->id, 'code' => 'VERANO', 'type' => CouponType::Percent, 'value' => 10, 'used_count' => 2]);
        $order = $this->order($this->coppers, $this->ana, 'CP-00001', OrderStatus::Reserved, [[$variant, 2]], ['coupon_id' => $coupon->id]);

        $this->asAna()->post(self::COPPERS.'/mi-cuenta/pedidos/CP-00001/cancelar')->assertSessionHasNoErrors();
        $this->asAna()->post(self::COPPERS.'/mi-cuenta/pedidos/CP-00001/cancelar')
            ->assertRedirect(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')
            ->assertSessionHasErrors(['order' => 'Este pedido ya no se puede cancelar desde aquí.']);
        $this->asAna()->post(self::COPPERS.'/mi-cuenta/pedidos/CP-00001/cancelar');

        $this->assertSame(5, $variant->fresh()->stock);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(1, $coupon->fresh()->used_count);
        // Historial: creado y cancelado, una sola vez.
        $this->assertSame(2, $order->statusHistories()->count());
    }

    // ---- 3. Pagado: solo se solicita -----------------------------------------------------------

    public function test_on_a_paid_order_the_customer_only_requests_and_the_staff_is_notified()
    {
        $this->travelTo('2026-10-06 12:00:00');
        $variant = $this->variant($this->coppers, 'Camisa', 3);
        $order = $this->order($this->coppers, $this->ana, 'CP-00001', OrderStatus::Paid, [[$variant, 2]]);

        // No se puede cancelar directo...
        $this->asAna()->post(self::COPPERS.'/mi-cuenta/pedidos/CP-00001/cancelar')->assertSessionHasErrors('order');
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);

        $props = $this->props(self::COPPERS.'/mi-cuenta/pedidos/CP-00001');
        $this->assertSame(['cancel' => false, 'request_cancellation' => true], $props['can']);
        $this->assertFalse($props['cancellationRequested']);

        // ...solo solicitarlo.
        $this->asAna()->post(self::COPPERS.'/mi-cuenta/pedidos/CP-00001/solicitar-cancelacion', ['note' => '  Ya no lo necesito  '])
            ->assertRedirect(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')
            ->assertSessionHas('status', 'Recibimos tu solicitud. La estamos revisando.');

        $order->refresh();

        // Sigue pagado, con sus piezas apartadas.
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNull($order->cancelled_at);
        $this->assertTrue($order->cancellation_requested_at->equalTo(now()));
        $this->assertSame('Ya no lo necesito', $order->cancellation_request_note);
        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertSame(0, StockMovement::count());

        // Historial con la clienta como autora.
        $row = $order->statusHistories()->get()->last();
        $this->assertSame([OrderStatus::Paid, OrderStatus::Paid, $this->ana->id, null], [$row->from_status, $row->to_status, $row->customer_id, $row->user_id]);
        $this->assertSame('El cliente solicitó cancelar el pedido. Motivo: Ya no lo necesito', $row->note);

        // Campana: el owner y el staff de Copper's; el staff de G5 no.
        $this->assertSame(1, $this->requestNotices($this->owner)->count());
        $this->assertSame(1, $this->requestNotices($this->coppersStaff)->count());
        $this->assertSame(0, $this->requestNotices($this->g5Staff)->count());

        $data = $this->requestNotices($this->coppersStaff)->first()->data;
        $this->assertSame('Solicitud de cancelación', $data['title']);
        $this->assertStringContainsString('CP-00001', $data['body']);
        $this->assertStringContainsString('Ana Pérez', $data['body']);
        $this->assertStringContainsString('Ya no lo necesito', $data['body']);

        // A la clienta: «Solicitud en revisión», sin poder pedirlo otra vez.
        $props = $this->props(self::COPPERS.'/mi-cuenta/pedidos/CP-00001');
        $this->assertTrue($props['cancellationRequested']);
        $this->assertSame(['cancel' => false, 'request_cancellation' => false], $props['can']);
        $this->assertSame('Pagado', $props['order']['status_label']);
    }

    public function test_there_is_only_one_active_request_per_order()
    {
        $order = $this->order($this->coppers, $this->ana, 'CP-00001', OrderStatus::Paid);

        $this->asAna()->post(self::COPPERS.'/mi-cuenta/pedidos/CP-00001/solicitar-cancelacion', ['note' => 'Primera']);
        $this->asAna()->post(self::COPPERS.'/mi-cuenta/pedidos/CP-00001/solicitar-cancelacion', ['note' => 'Segunda'])
            ->assertSessionHasErrors(['order' => 'Ya tienes una solicitud de cancelación en revisión para este pedido.']);

        $this->assertSame('Primera', $order->fresh()->cancellation_request_note);
        $this->assertSame(1, $this->requestNotices($this->owner)->count());
        $this->assertSame(2, $order->statusHistories()->count());
    }

    // ---- 4. Enviado: ni cancelar ni solicitar --------------------------------------------------

    public function test_a_shipped_delivered_or_cancelled_order_can_not_be_cancelled_or_requested()
    {
        $variant = $this->variant($this->coppers, 'Camisa', 3);

        foreach ([OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Cancelled] as $index => $status) {
            $number = 'CP-0000'.($index + 1);
            $order = $this->order($this->coppers, $this->ana, $number, $status, [[$variant, 1]]);

            $this->asAna()->post(self::COPPERS."/mi-cuenta/pedidos/{$number}/cancelar")->assertSessionHasErrors('order');
            $this->asAna()->post(self::COPPERS."/mi-cuenta/pedidos/{$number}/solicitar-cancelacion", ['note' => 'Por favor'])
                ->assertSessionHasErrors(['order' => 'Este pedido ya no admite una solicitud de cancelación.']);

            $order->refresh();

            $this->assertSame($status, $order->status, $status->value);
            $this->assertNull($order->cancellation_requested_at, $status->value);

            $props = $this->props(self::COPPERS."/mi-cuenta/pedidos/{$number}");
            $this->assertSame(['cancel' => false, 'request_cancellation' => false], $props['can'], $status->value);
            $this->assertFalse($props['cancellationRequested']);
            // Queda el botón de WhatsApp para cambios o devoluciones.
            $this->assertStringStartsWith('https://wa.me/527551111111?text=', $props['whatsapp']['question_url']);
        }

        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, $this->requestNotices($this->owner)->count());
    }

    public function test_a_reserved_order_is_cancelled_not_requested()
    {
        $order = $this->order($this->coppers, $this->ana, 'CP-00001');

        $this->asAna()->post(self::COPPERS.'/mi-cuenta/pedidos/CP-00001/solicitar-cancelacion')->assertSessionHasErrors('order');

        $this->assertNull($order->fresh()->cancellation_requested_at);
        $this->assertSame(0, $this->requestNotices($this->owner)->count());
    }

    // ---- 6. Línea de tiempo --------------------------------------------------------------------

    public function test_the_customers_timeline_has_only_statuses_and_dates_without_internal_notes_or_staff_names()
    {
        $this->travelTo('2026-10-05 10:00:00');
        $order = $this->order($this->coppers, $this->ana, 'CP-00001', OrderStatus::Reserved, extra: ['delivery_method' => DeliveryMethod::National, 'internal_notes' => 'Cliente conflictivo']);
        $statuses = app(OrderStatusService::class);

        $this->travelTo('2026-10-06 09:15:00');
        $statuses->transition($order, OrderStatus::Paid, $this->coppersStaff, 'Depósito dudoso, revisar con el banco', ['payment_reference' => 'REF-SECRETA']);
        $statuses->annotate($order, $this->coppersStaff, 'Se dieron 24 horas más para pagar.');
        $this->travelTo('2026-10-07 16:40:00');
        $statuses->transition($order, OrderStatus::Shipped, $this->owner, 'Salió con el repartidor de siempre', ['tracking_carrier' => 'Estafeta', 'tracking_number' => 'ABC123']);

        $response = $this->asAna()->get(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('order.timeline', [
                ['label' => 'Apartado', 'date' => '5 de octubre de 2026, 10:00'],
                ['label' => 'Pagado', 'date' => '6 de octubre de 2026, 09:15'],
                ['label' => 'En camino', 'date' => '7 de octubre de 2026, 16:40'],
            ])
            ->missing('order.internal_notes')
            ->missing('order.payment_reference')
            ->missing('order.cancel_reason'));

        // Nada interno viaja al navegador, ni en la línea de tiempo ni en otro lado.
        $html = $response->getContent();

        foreach (['Cliente conflictivo', 'Depósito dudoso', 'repartidor de siempre', '24 horas más', 'REF-SECRETA', 'Laura Staff', 'Dueña Olga'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, $secret);
            $this->assertStringNotContainsString(json_encode($secret), $html, $secret);
        }
    }

    public function test_the_reason_staff_wrote_when_cancelling_is_not_shown_to_the_customer()
    {
        $order = $this->order($this->coppers, $this->ana, 'CP-00001', OrderStatus::Cancelled, extra: ['cancel_reason' => 'Cliente no pagó: nunca contesta', 'cancelled_by' => CancelledBy::Admin]);

        $html = $this->asAna()->get(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')->assertOk()->getContent();

        $this->assertStringNotContainsString('nunca contesta', $html);
    }

    // ---- Panel: la solicitud ---------------------------------------------------------------------

    // ---- Botón de duda por WhatsApp: siempre, en cualquier estado ---------------------------------

    public function test_the_question_button_is_there_in_every_status_with_the_stores_whatsapp()
    {
        foreach (OrderStatus::cases() as $position => $status) {
            $number = 'CP-0000'.($position + 1);
            $this->order($this->coppers, $this->ana, $number, $status);

            $text = $status === OrderStatus::Cancelled
                ? "Hola, tengo una duda sobre mi pedido {$number} (cancelado)."
                : "Hola, tengo una duda sobre mi pedido {$number}.";

            $this->assertSame(
                'https://wa.me/527551111111?text='.rawurlencode($text),
                $this->props(self::COPPERS."/mi-cuenta/pedidos/{$number}")['whatsapp']['question_url'],
                "Estado {$status->value}",
            );
        }

        $this->assertCount(5, OrderStatus::cases());

        // En la otra tienda sale con el WhatsApp de esa tienda.
        $this->order($this->g5, $this->ana, 'G5-00001', OrderStatus::Paid);
        $this->assertStringStartsWith('https://wa.me/527552222222?text=', $this->props(self::G5.'/mi-cuenta/pedidos/G5-00001')['whatsapp']['question_url']);
    }

    public function test_without_a_store_whatsapp_there_is_no_question_button_in_any_status()
    {
        $this->coppers->update(['whatsapp_phone' => null]);

        foreach (OrderStatus::cases() as $position => $status) {
            $number = 'CP-0000'.($position + 1);
            $this->order($this->coppers, $this->ana, $number, $status);

            $props = $this->props(self::COPPERS."/mi-cuenta/pedidos/{$number}");

            $this->assertSame(['receipt_url' => null, 'question_url' => null], $props['whatsapp'], "Estado {$status->value}");
        }
    }

    public function test_the_question_of_someone_elses_order_or_of_the_other_store_is_not_reachable()
    {
        $this->order($this->coppers, $this->luis, 'CP-00001', OrderStatus::Cancelled);
        $this->order($this->g5, $this->ana, 'G5-00001', OrderStatus::Delivered);

        $this->asAna()->get(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')->assertNotFound();
        $this->asAna()->get(self::COPPERS.'/mi-cuenta/pedidos/G5-00001')->assertNotFound();
    }

    private function panelAs(User $user, Store $store): void
    {
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($store);
    }

    public function test_the_panel_shows_the_request_with_its_note_and_the_staff_can_reject_it()
    {
        $order = $this->order($this->coppers, $this->ana, 'CP-00001', OrderStatus::Paid);
        app(CancellationRequestService::class)->request($order, $this->ana, 'Ya no lo necesito');
        $this->panelAs($this->coppersStaff, $this->coppers);

        Livewire::test(ListOrders::class)->assertTableColumnStateSet('marks', ['Solicitó cancelar'], $order->fresh());

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('Solicitó cancelar')
            ->assertSee('Ya no lo necesito')
            ->assertActionVisible('rejectCancellation')
            ->callAction('rejectCancellation', data: ['note' => 'Ya va en camino a la paquetería'])
            ->assertHasNoActionErrors();

        $order->refresh();

        $this->assertNull($order->cancellation_requested_at);
        $this->assertNull($order->cancellation_request_note);
        $this->assertSame(OrderStatus::Paid, $order->status);

        $row = $order->statusHistories()->get()->last();
        $this->assertSame($this->coppersStaff->id, $row->user_id);
        $this->assertSame('Solicitud de cancelación rechazada. Ya va en camino a la paquetería', $row->note);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('rejectCancellation');
        Livewire::test(ListOrders::class)->assertTableColumnStateSet('marks', null, $order);

        // La clienta ya no ve «Solicitud en revisión», no ve la nota del personal y puede volver a solicitar.
        $response = $this->actingAs($this->ana, 'customer')->get(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('cancellationRequested', false)
            ->where('can.request_cancellation', true));
        $this->assertStringNotContainsString('paqueter', $response->getContent());
    }

    public function test_the_owner_can_accept_the_request_by_cancelling_the_paid_order()
    {
        $variant = $this->variant($this->coppers, 'Camisa', 3);
        $order = $this->order($this->coppers, $this->ana, 'CP-00001', OrderStatus::Paid, [[$variant, 2]]);
        app(CancellationRequestService::class)->request($order, $this->ana, 'Ya no lo necesito');
        $this->panelAs($this->owner, $this->coppers);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('cancel', data: ['reason' => 'Cliente pidió cancelar', 'refunded' => true])
            ->assertHasNoActionErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(5, $variant->fresh()->stock);

        $props = $this->props(self::COPPERS.'/mi-cuenta/pedidos/CP-00001');
        $this->assertFalse($props['cancellationRequested']);
        $this->assertSame('Cancelado', $props['order']['status_label']);
    }

    public function test_rejecting_needs_a_request()
    {
        $order = $this->order($this->coppers, $this->ana, 'CP-00001', OrderStatus::Paid);
        $this->panelAs($this->coppersStaff, $this->coppers);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('rejectCancellation');
    }
}
