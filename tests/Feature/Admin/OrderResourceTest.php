<?php

namespace Tests\Feature\Admin;

use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderStatusService;
use App\Support\OrderWhatsAppMessage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $coppers;

    private Store $g5;

    private Customer $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost']);
        $this->ana = Customer::factory()->create(['name' => 'Ana Pérez', 'email' => 'ana@example.com', 'phone' => '755 123 4567']);
    }

    private function panelAs(UserRole $role, Store $store): User
    {
        $user = User::factory()->create(['role' => $role]);

        if ($role === UserRole::Staff) {
            $user->stores()->attach($store->id);
        }

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($store);

        return $user;
    }

    private function order(Store $store, string $number, OrderStatus $status = OrderStatus::Reserved, DeliveryMethod $method = DeliveryMethod::Pickup, array $extra = []): Order
    {
        return Order::create([
            'store_id' => $store->id,
            'customer_id' => $this->ana->id,
            'number' => $number,
            'channel' => OrderChannel::Web,
            'status' => $status,
            'delivery_method' => $method,
            'subtotal' => 1500,
            'total' => 1500,
            ...$extra,
        ]);
    }

    private function detail(Order $order)
    {
        return Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);
    }

    // ---- 4. Cada quien ve solo su tienda ---------------------------------------------------------

    public function test_g5_staff_does_not_see_coppers_orders()
    {
        $mine = $this->order($this->g5, 'G5-00001');
        $theirs = $this->order($this->coppers, 'CP-00001');
        $this->panelAs(UserRole::Staff, $this->g5);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->searchTable('CP-00001')
            ->assertCanNotSeeTableRecords([$theirs]);

        $this->get('/admin/g5/orders')->assertOk()->assertSee('G5-00001')->assertDontSee('CP-00001');
        $this->get('/admin/g5/orders/'.$mine->id)->assertOk();

        // Ni entrando a la tienda ajena, ni pidiendo el pedido ajeno dentro de la propia.
        $this->get('/admin/coppers/orders')->assertNotFound();
        $this->get('/admin/coppers/orders/'.$theirs->id)->assertNotFound();
        $this->get('/admin/g5/orders/'.$theirs->id)->assertNotFound();
    }

    public function test_the_owner_sees_each_stores_orders_in_its_own_panel()
    {
        $g5Order = $this->order($this->g5, 'G5-00001');
        $coppersOrder = $this->order($this->coppers, 'CP-00001');
        $this->panelAs(UserRole::Owner, $this->coppers);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$coppersOrder])
            ->assertCanNotSeeTableRecords([$g5Order]);
    }

    // ---- Lista ---------------------------------------------------------------------------------

    public function test_the_list_shows_newest_first_with_customer_or_counter_and_marks()
    {
        $old = $this->order($this->g5, 'G5-00001');
        $old->forceFill(['created_at' => now()->subDays(2)])->save();
        $counter = $this->order($this->g5, 'G5-00002', extra: ['customer_id' => null, 'channel' => OrderChannel::Counter]);
        $counter->forceFill(['created_at' => now()->subDay()])->save();
        $flagged = $this->order($this->g5, 'G5-00003', extra: ['cancellation_requested_at' => now(), 'cancellation_request_note' => 'Me equivoqué de talla']);
        $flagged->items()->create(['product_name' => 'Tenis', 'size' => '27', 'unit_price' => 1500, 'quantity' => 1, 'is_backorder' => true]);
        $old->items()->create(['product_name' => 'Tenis', 'size' => '27', 'unit_price' => 1500, 'quantity' => 1, 'is_backorder' => false]);
        $this->panelAs(UserRole::Staff, $this->g5);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$flagged, $counter, $old], inOrder: true)
            ->assertTableColumnStateSet('customer.name', 'Ana Pérez', $old)
            ->assertTableColumnStateSet('marks', ['Sobre pedido', 'Solicitó cancelar'], $flagged)
            ->assertTableColumnStateSet('marks', null, $old)
            ->assertSee('Mostrador')
            ->assertSee('Sobre pedido')
            ->assertSee('Solicitó cancelar');
    }

    public function test_the_shipped_label_in_the_list_depends_on_the_delivery_method()
    {
        $pickup = $this->order($this->g5, 'G5-00001', OrderStatus::Shipped, DeliveryMethod::Pickup);
        $local = $this->order($this->g5, 'G5-00002', OrderStatus::Shipped, DeliveryMethod::Local);
        $national = $this->order($this->g5, 'G5-00003', OrderStatus::Shipped, DeliveryMethod::National);
        $paid = $this->order($this->g5, 'G5-00004', OrderStatus::Paid, DeliveryMethod::Pickup);
        $this->panelAs(UserRole::Staff, $this->g5);

        Livewire::test(ListOrders::class)
            ->assertTableColumnFormattedStateSet('status', 'Listo para recoger', $pickup)
            ->assertTableColumnFormattedStateSet('status', 'En camino', $local)
            ->assertTableColumnFormattedStateSet('status', 'En camino', $national)
            ->assertTableColumnFormattedStateSet('status', 'Pagado', $paid);

        $this->detail($pickup)->assertSee('Listo para recoger')->assertDontSee('En camino');
        $this->detail($national)->assertSee('En camino')->assertDontSee('Listo para recoger');
    }

    public function test_the_list_filters_by_status_delivery_channel_dates_backorder_and_cancellation_request()
    {
        $reserved = $this->order($this->g5, 'G5-00001');
        $reserved->forceFill(['created_at' => '2026-09-01 10:00:00'])->save();
        $paidNational = $this->order($this->g5, 'G5-00002', OrderStatus::Paid, DeliveryMethod::National);
        $paidNational->forceFill(['created_at' => '2026-09-15 10:00:00'])->save();
        $counter = $this->order($this->g5, 'G5-00003', OrderStatus::Paid, extra: ['customer_id' => null, 'channel' => OrderChannel::Counter]);
        $counter->forceFill(['created_at' => '2026-09-30 10:00:00'])->save();
        $paidNational->items()->create(['product_name' => 'Tenis', 'size' => '27', 'unit_price' => 1500, 'quantity' => 1, 'is_backorder' => true]);
        $reserved->update(['cancellation_requested_at' => now()]);
        $this->panelAs(UserRole::Staff, $this->g5);

        Livewire::test(ListOrders::class)
            ->filterTable('status', 'pagado')
            ->assertCanSeeTableRecords([$paidNational, $counter])->assertCanNotSeeTableRecords([$reserved])
            ->resetTableFilters()
            ->filterTable('delivery_method', 'nacional')
            ->assertCanSeeTableRecords([$paidNational])->assertCanNotSeeTableRecords([$reserved, $counter])
            ->resetTableFilters()
            ->filterTable('channel', 'mostrador')
            ->assertCanSeeTableRecords([$counter])->assertCanNotSeeTableRecords([$reserved, $paidNational])
            ->resetTableFilters()
            ->filterTable('created_at', ['from' => '2026-09-10', 'until' => '2026-09-20'])
            ->assertCanSeeTableRecords([$paidNational])->assertCanNotSeeTableRecords([$reserved, $counter])
            ->resetTableFilters()
            ->filterTable('backorder')
            ->assertCanSeeTableRecords([$paidNational])->assertCanNotSeeTableRecords([$reserved, $counter])
            ->resetTableFilters()
            ->filterTable('cancellation_requested')
            ->assertCanSeeTableRecords([$reserved])->assertCanNotSeeTableRecords([$paidNational, $counter]);
    }

    public function test_the_list_searches_by_number_name_email_and_phone()
    {
        $luis = Customer::factory()->create(['name' => 'Luis Gómez', 'email' => 'luis@correo.mx', 'phone' => '744 555 0000']);
        $anaOrder = $this->order($this->g5, 'G5-00001');
        $luisOrder = $this->order($this->g5, 'G5-00077', extra: ['customer_id' => $luis->id]);
        $this->panelAs(UserRole::Staff, $this->g5);

        foreach (['G5-00077', 'Gómez', 'luis@correo', '744 555'] as $search) {
            Livewire::test(ListOrders::class)
                ->searchTable($search)
                ->assertCanSeeTableRecords([$luisOrder])
                ->assertCanNotSeeTableRecords([$anaOrder]);
        }
    }

    // ---- Detalle -------------------------------------------------------------------------------

    public function test_the_detail_shows_customer_address_lines_totals_and_the_history_timeline()
    {
        $order = $this->order($this->g5, 'G5-00001', OrderStatus::Reserved, DeliveryMethod::Local, [
            'shipping_address' => ['street' => 'Calle Hidalgo 5', 'neighborhood' => 'Centro', 'city' => 'Zihuatanejo', 'state' => 'Guerrero', 'zip' => '40880', 'name' => 'Ana Pérez', 'phone' => '755 123 4567'],
            'notes' => 'Tocar el timbre',
            'expires_at' => now()->addDay(),
        ]);
        $order->items()->create(['product_name' => 'Tenis Mercurial', 'size' => '27', 'unit_price' => 1500, 'quantity' => 1]);
        $staff = $this->panelAs(UserRole::Staff, $this->g5);
        $statuses = app(OrderStatusService::class);
        $statuses->recordInitial($order, $this->ana);
        $statuses->transition($order, OrderStatus::Paid, $staff, 'Depósito en ventanilla', ['payment_reference' => 'REF-99']);

        $this->detail($order)
            ->assertSee('G5-00001')
            ->assertSee('Ana Pérez')
            ->assertSee('ana@example.com')
            ->assertSee('755 123 4567')
            ->assertSee('Calle Hidalgo 5')
            ->assertSee('Tenis Mercurial')
            ->assertSee('Tocar el timbre')
            ->assertSee('Fecha límite de pago')
            ->assertSee('REF-99')
            // Historial, en orden: creado por la clienta y pagado por el staff.
            ->assertSeeInOrder(['Pedido creado: Apartado', 'Ana Pérez (cliente)', 'Apartado → Pagado', $staff->name, 'Depósito en ventanilla']);
    }

    // ---- Acciones ------------------------------------------------------------------------------

    public function test_marking_paid_asks_for_the_reference_and_records_who_did_it()
    {
        $this->travelTo('2026-10-06 12:00:00');
        $order = $this->order($this->g5, 'G5-00001');
        $staff = $this->panelAs(UserRole::Staff, $this->g5);

        $this->detail($order)
            ->assertActionVisible('markPaid')
            ->assertActionHidden('markShipped')
            ->assertActionHidden('markDelivered')
            ->callAction('markPaid', data: ['payment_reference' => ''])
            ->assertHasActionErrors(['payment_reference' => 'required']);

        $this->assertSame(OrderStatus::Reserved, $order->fresh()->status);

        $this->detail($order)
            ->callAction('markPaid', data: ['payment_reference' => 'REF-123'])
            ->assertHasNoActionErrors();

        $order->refresh();

        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('REF-123', $order->payment_reference);
        $this->assertTrue($order->paid_at->equalTo(now()));

        $row = $order->statusHistories()->sole();
        $this->assertSame([OrderStatus::Reserved, OrderStatus::Paid, $staff->id], [$row->from_status, $row->to_status, $row->user_id]);
    }

    public function test_a_national_order_is_not_marked_shipped_without_carrier_and_tracking_number()
    {
        $order = $this->order($this->g5, 'G5-00001', OrderStatus::Paid, DeliveryMethod::National);
        $this->panelAs(UserRole::Staff, $this->g5);

        $this->detail($order)
            ->assertActionVisible('markShipped')
            ->callAction('markShipped', data: ['tracking_carrier' => '', 'tracking_number' => ''])
            ->assertHasActionErrors(['tracking_carrier' => 'required', 'tracking_number' => 'required']);

        $this->detail($order)
            ->callAction('markShipped', data: ['tracking_carrier' => 'Estafeta', 'tracking_number' => ''])
            ->assertHasActionErrors(['tracking_number' => 'required']);

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame(0, $order->statusHistories()->count());

        $this->detail($order)
            ->callAction('markShipped', data: ['tracking_carrier' => 'Estafeta', 'tracking_number' => 'ABC123'])
            ->assertHasNoActionErrors();

        $order->refresh();

        $this->assertSame(OrderStatus::Shipped, $order->status);
        $this->assertSame(['Estafeta', 'ABC123'], [$order->tracking_carrier, $order->tracking_number]);
    }

    public function test_pickup_and_local_orders_are_marked_shipped_without_tracking()
    {
        $pickup = $this->order($this->g5, 'G5-00001', OrderStatus::Paid, DeliveryMethod::Pickup);
        $local = $this->order($this->g5, 'G5-00002', OrderStatus::Paid, DeliveryMethod::Local);
        $this->panelAs(UserRole::Owner, $this->g5);

        $this->detail($pickup)->assertActionHasLabel('markShipped', 'Marcar listo para recoger')->callAction('markShipped')->assertHasNoActionErrors();
        $this->detail($local)->assertActionHasLabel('markShipped', 'Marcar enviado')->callAction('markShipped')->assertHasNoActionErrors();

        $this->assertSame('Listo para recoger', $pickup->fresh()->statusLabel());
        $this->assertSame('En camino', $local->fresh()->statusLabel());
    }

    public function test_marking_delivered_sets_the_delivery_date_and_ends_the_actions()
    {
        $this->travelTo('2026-10-08 18:30:00');
        $order = $this->order($this->g5, 'G5-00001', OrderStatus::Shipped);
        $this->panelAs(UserRole::Staff, $this->g5);

        $this->detail($order)
            ->assertActionHidden('markPaid')
            ->assertActionHidden('markShipped')
            ->callAction('markDelivered')
            ->assertHasNoActionErrors();

        $order->refresh();

        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertTrue($order->delivered_at->equalTo(now()));

        $this->detail($order)
            ->assertActionHidden('markPaid')
            ->assertActionHidden('markShipped')
            ->assertActionHidden('markDelivered');
    }

    public function test_internal_notes_are_edited_apart_from_the_customers_notes()
    {
        $order = $this->order($this->g5, 'G5-00001', extra: ['notes' => 'Tocar el timbre']);
        $this->panelAs(UserRole::Staff, $this->g5);

        $this->detail($order)
            ->callAction('editNotes', data: ['internal_notes' => '  Cliente frecuente  '])
            ->assertHasNoActionErrors();

        $order->refresh();

        $this->assertSame('Cliente frecuente', $order->internal_notes);
        $this->assertSame('Tocar el timbre', $order->notes);
    }

    public function test_the_address_can_only_be_edited_before_the_order_ships()
    {
        $address = ['street' => 'Calle Hidalgo 5', 'neighborhood' => 'Centro', 'city' => 'Zihuatanejo', 'state' => 'Guerrero', 'zip' => '40880', 'notes' => null, 'name' => 'Ana Pérez', 'phone' => '755 123 4567'];
        $reserved = $this->order($this->g5, 'G5-00001', OrderStatus::Reserved, DeliveryMethod::Local, ['shipping_address' => $address]);
        $paid = $this->order($this->g5, 'G5-00002', OrderStatus::Paid, DeliveryMethod::National, ['shipping_address' => $address]);
        $shipped = $this->order($this->g5, 'G5-00003', OrderStatus::Shipped, DeliveryMethod::Local, ['shipping_address' => $address]);
        $delivered = $this->order($this->g5, 'G5-00004', OrderStatus::Delivered, DeliveryMethod::Local, ['shipping_address' => $address]);
        $pickup = $this->order($this->g5, 'G5-00005', OrderStatus::Reserved, DeliveryMethod::Pickup);
        $this->panelAs(UserRole::Staff, $this->g5);

        $this->detail($paid)->assertActionVisible('editAddress');
        $this->detail($shipped)->assertActionHidden('editAddress');
        $this->detail($delivered)->assertActionHidden('editAddress');
        $this->detail($pickup)->assertActionHidden('editAddress');

        $this->detail($reserved)
            ->assertActionVisible('editAddress')
            ->callAction('editAddress', data: ['street' => 'Av. Palmas 12', 'zip' => '123'])
            ->assertHasActionErrors(['zip']);

        $this->detail($reserved)
            ->callAction('editAddress', data: ['street' => 'Av. Palmas 12', 'zip' => '40890'])
            ->assertHasNoActionErrors();

        $saved = $reserved->fresh()->shipping_address;

        $this->assertSame('Av. Palmas 12', $saved['street']);
        $this->assertSame('40890', $saved['zip']);
        $this->assertSame('Centro', $saved['neighborhood']);
        $this->assertSame('755 123 4567', $saved['phone']);
    }

    // ---- 6. WhatsApp ---------------------------------------------------------------------------

    public function test_the_whatsapp_button_opens_the_customers_chat_with_the_order_number()
    {
        $paid = $this->order($this->g5, 'G5-00042', OrderStatus::Paid);
        $reserved = $this->order($this->g5, 'G5-00043');
        $this->panelAs(UserRole::Staff, $this->g5);

        $url = OrderWhatsAppMessage::statusNoticeUrl($paid->fresh());

        $this->assertStringStartsWith('https://wa.me/527551234567?text=', $url);
        $this->assertStringContainsString('G5-00042', rawurldecode($url));

        $this->detail($paid)
            ->assertActionVisible('whatsapp')
            ->assertActionHasUrl('whatsapp', $url)
            ->assertActionShouldOpenUrlInNewTab('whatsapp');

        // Un apartado todavía no tiene aviso; sin teléfono tampoco hay botón.
        $this->detail($reserved)->assertActionHidden('whatsapp');

        $this->ana->update(['phone' => null]);
        $this->detail($paid)->assertActionHidden('whatsapp');
    }

    // ---- Roles ---------------------------------------------------------------------------------

    public function test_owner_and_staff_can_both_move_an_order()
    {
        foreach ([UserRole::Owner, UserRole::Staff] as $index => $role) {
            $order = $this->order($this->g5, 'G5-0000'.($index + 1));
            $user = $this->panelAs($role, $this->g5);

            $this->detail($order)->callAction('markPaid', data: ['payment_reference' => 'REF'])->assertHasNoActionErrors();

            $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
            $this->assertSame($user->id, $order->statusHistories()->sole()->user_id);
        }
    }

    public function test_orders_are_not_created_from_the_panel()
    {
        $this->panelAs(UserRole::Owner, $this->g5);

        $this->get('/admin/g5/orders/create')->assertNotFound();
    }
}
