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
use App\Exceptions\InvalidOrderTransitionException;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderCancellationService;
use App\Services\OrderReservationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private Store $g5;

    private Customer $ana;

    private User $owner;

    private User $staff;

    private OrderCancellationService $cancellations;

    private OrderReservationService $reservations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost']);
        $this->ana = Customer::factory()->create(['name' => 'Ana Pérez', 'phone' => '7551234567']);
        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->staff = User::factory()->create(['role' => UserRole::Staff]);
        $this->staff->stores()->attach($this->g5->id);
        $this->cancellations = app(OrderCancellationService::class);
        $this->reservations = app(OrderReservationService::class);
    }

    /** Una talla con el stock que queda en el estante (ya descontado lo que tengan los pedidos). */
    private function variant(string $name, int $stock, string $size = 'M'): ProductVariant
    {
        $category = Category::firstOrCreate(
            ['store_id' => $this->g5->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        return $category->products()
            ->create(['store_id' => $this->g5->id, 'name' => $name, 'slug' => str($name)->slug(), 'price' => 500, 'allow_backorder' => true])
            ->variants()->create(['size' => $size, 'stock' => $stock]);
    }

    /** @param list<array{0: ProductVariant, 1: int, 2?: bool}> $lines talla, cantidad, sobre pedido */
    private function order(array $lines, OrderStatus $status = OrderStatus::Reserved, array $extra = []): Order
    {
        static $sequence = 0;

        $order = Order::create([
            'store_id' => $this->g5->id,
            'customer_id' => $this->ana->id,
            'number' => sprintf('G5-%05d', ++$sequence),
            'channel' => OrderChannel::Web,
            'status' => $status,
            'delivery_method' => DeliveryMethod::Pickup,
            'subtotal' => 1000,
            'total' => 1000,
            'expires_at' => now()->addHours(48),
            'paid_at' => $status === OrderStatus::Paid ? now() : null,
            ...$extra,
        ]);

        foreach ($lines as $line) {
            [$variant, $quantity] = $line;

            $order->items()->create([
                'product_variant_id' => $variant->id,
                'product_name' => $variant->product()->withoutGlobalScopes()->first()->name,
                'size' => $variant->size,
                'unit_price' => 500,
                'quantity' => $quantity,
                'is_backorder' => $line[2] ?? false,
            ]);
        }

        return $order;
    }

    private function panelAs(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($this->g5);
    }

    private function detail(Order $order)
    {
        return Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);
    }

    // ---- 1. Cancelar regresa el stock -----------------------------------------------------------

    public function test_cancelling_returns_the_stock_and_creates_a_cancellation_movement()
    {
        $this->travelTo('2026-10-06 12:00:00');
        $shirt = $this->variant('Camisa', 3);
        $shoes = $this->variant('Tenis', 0, '27');
        $order = $this->order([[$shirt, 2], [$shoes, 1]]);

        $this->cancellations->cancel($order, $this->staff, 'Cliente no pagó');

        $this->assertSame(5, $shirt->fresh()->stock);
        $this->assertSame(1, $shoes->fresh()->stock);

        $movements = StockMovement::where('order_id', $order->id)->get()->keyBy('product_variant_id');

        $this->assertCount(2, $movements);
        $this->assertSame(StockMovementType::Cancellation, $movements[$shirt->id]->type);
        $this->assertSame(2, $movements[$shirt->id]->quantity);
        $this->assertSame(1, $movements[$shoes->id]->quantity);
        $this->assertSame($this->staff->id, $movements[$shirt->id]->user_id);
        $this->assertSame("Cancelación del pedido {$order->number}", $movements[$shirt->id]->notes);

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertTrue($order->cancelled_at->equalTo(now()));
        $this->assertSame('Cliente no pagó', $order->cancel_reason);
        $this->assertSame(CancelledBy::Admin, $order->cancelled_by);
        $this->assertNull($order->refunded_at);

        $row = $order->statusHistories()->sole();
        $this->assertSame([OrderStatus::Reserved, OrderStatus::Cancelled, $this->staff->id, 'Cliente no pagó'], [$row->from_status, $row->to_status, $row->user_id, $row->note]);
    }

    public function test_cancelled_by_tells_customer_admin_and_system_apart()
    {
        $variant = $this->variant('Camisa', 0);
        $byCustomer = $this->order([[$variant, 1]]);
        $bySystem = $this->order([[$variant, 1]]);

        $this->cancellations->cancel($byCustomer, $this->ana, 'Ya no lo quiero');
        $this->cancellations->cancel($bySystem, null, 'Venció el apartado');

        $this->assertSame(CancelledBy::Customer, $byCustomer->cancelled_by);
        $this->assertSame($this->ana->id, $byCustomer->statusHistories()->sole()->customer_id);
        $this->assertSame(CancelledBy::System, $bySystem->cancelled_by);
        $this->assertSame(2, $variant->fresh()->stock);

        // Los movimientos del cliente y del sistema no llevan usuario del panel.
        $this->assertSame([null, null], StockMovement::pluck('user_id')->all());
    }

    public function test_the_stock_returns_even_if_the_size_is_in_the_trash()
    {
        $variant = $this->variant('Camisa', 1);
        $order = $this->order([[$variant, 2]]);
        $variant->delete();

        $this->cancellations->cancel($order, $this->staff, 'Error de captura');

        $this->assertSame(3, ProductVariant::withTrashed()->find($variant->id)->stock);
        $this->assertSame(1, StockMovement::count());
    }

    // ---- 2. Sin regresar piezas -----------------------------------------------------------------

    public function test_with_restock_off_the_stock_does_not_change_and_the_history_says_so()
    {
        $variant = $this->variant('Camisa', 3);
        $order = $this->order([[$variant, 2]]);

        $this->cancellations->cancel($order, $this->staff, 'Producto dañado', restock: false);

        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('Producto dañado', $order->cancel_reason);
        $this->assertSame('Producto dañado. No se regresaron piezas al inventario.', $order->statusHistories()->sole()->note);
    }

    // ---- 3. Sobre pedido ------------------------------------------------------------------------

    public function test_backorder_lines_do_not_touch_the_stock_when_cancelling()
    {
        $variant = $this->variant('Camisa', 0);
        $other = $this->variant('Pantalón', 4);
        // 2 piezas apartadas del estante y 3 más sobre pedido de la misma talla; otra línea solo sobre pedido.
        $order = $this->order([[$variant, 2], [$variant, 3, true], [$other, 5, true]]);

        $this->cancellations->cancel($order, $this->staff, 'Cliente pidió cancelar');

        $this->assertSame(2, $variant->fresh()->stock);
        $this->assertSame(4, $other->fresh()->stock);

        $movement = StockMovement::sole();
        $this->assertSame([$variant->id, 2, StockMovementType::Cancellation], [$movement->product_variant_id, $movement->quantity, $movement->type]);
    }

    public function test_an_order_with_only_backorder_lines_cancels_without_movements()
    {
        $variant = $this->variant('Camisa', 0);
        $order = $this->order([[$variant, 2, true]]);

        $this->cancellations->cancel($order, $this->staff, 'Otro');

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(0, $variant->fresh()->stock);
        $this->assertSame(0, StockMovement::count());
    }

    // ---- 4. Cupón -------------------------------------------------------------------------------

    public function test_the_coupon_gets_its_use_back_and_never_goes_below_zero()
    {
        $variant = $this->variant('Camisa', 0);
        $used = Coupon::create(['store_id' => $this->g5->id, 'code' => 'USADO', 'type' => CouponType::Percent, 'value' => 10, 'used_count' => 3]);
        $zero = Coupon::create(['store_id' => $this->g5->id, 'code' => 'CERO', 'type' => CouponType::Percent, 'value' => 10, 'used_count' => 0]);
        $untouched = Coupon::create(['store_id' => $this->g5->id, 'code' => 'OTRO', 'type' => CouponType::Percent, 'value' => 10, 'used_count' => 7]);

        $this->cancellations->cancel($this->order([[$variant, 1]], extra: ['coupon_id' => $used->id]), $this->staff, 'Otro');
        $this->cancellations->cancel($this->order([[$variant, 1]], extra: ['coupon_id' => $zero->id]), $this->staff, 'Otro');
        $this->cancellations->cancel($this->order([[$variant, 1]]), $this->staff, 'Otro');

        $this->assertSame(2, $used->fresh()->used_count);
        $this->assertSame(0, $zero->fresh()->used_count);
        $this->assertSame(7, $untouched->fresh()->used_count);
    }

    // ---- 5. Quién cancela un pedido pagado ------------------------------------------------------

    public function test_staff_can_not_cancel_a_paid_order_but_the_owner_can()
    {
        $variant = $this->variant('Camisa', 3);
        $order = $this->order([[$variant, 2]], OrderStatus::Paid);

        foreach ([$this->staff, $this->ana, null] as $actor) {
            try {
                $this->cancellations->cancel($order, $actor, 'Cliente pidió cancelar');
                $this->fail('Alguien que no es el dueño canceló un pedido pagado.');
            } catch (InvalidOrderTransitionException $exception) {
                $this->assertStringContainsString('solo el dueño', $exception->getMessage());
            }
        }

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertSame(0, StockMovement::count());

        $this->cancellations->cancel($order, $this->owner, 'Cliente pidió cancelar');

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(5, $variant->fresh()->stock);
        $this->assertNotNull($order->paid_at);
    }

    public function test_the_panel_hides_cancel_from_staff_on_paid_orders()
    {
        $variant = $this->variant('Camisa', 3);
        $reserved = $this->order([[$variant, 1]]);
        $paid = $this->order([[$variant, 1]], OrderStatus::Paid);
        $shipped = $this->order([[$variant, 1]], OrderStatus::Shipped);

        $this->panelAs($this->staff);
        $this->detail($reserved)->assertActionVisible('cancel');
        $this->detail($paid)->assertActionHidden('cancel');
        $this->detail($shipped)->assertActionHidden('cancel');

        $this->panelAs($this->owner);
        $this->detail($reserved)->assertActionVisible('cancel');
        $this->detail($paid)->assertActionVisible('cancel');
        $this->detail($shipped)->assertActionHidden('cancel');
    }

    // ---- 6. Dos veces ---------------------------------------------------------------------------

    public function test_cancelling_twice_fails_and_does_not_return_the_stock_twice()
    {
        $variant = $this->variant('Camisa', 3);
        $coupon = Coupon::create(['store_id' => $this->g5->id, 'code' => 'UNO', 'type' => CouponType::Percent, 'value' => 10, 'used_count' => 2]);
        $order = $this->order([[$variant, 2]], extra: ['coupon_id' => $coupon->id]);

        $this->cancellations->cancel($order, $this->staff, 'Cliente no pagó');

        // Doble clic: la segunda llamada llega con el pedido como estaba antes de cancelar.
        $stale = Order::withoutGlobalScopes()->find($order->id);
        $stale->setRawAttributes([...$stale->getAttributes(), 'status' => 'apartado'], true);

        foreach ([$stale, $order] as $again) {
            try {
                $this->cancellations->cancel($again, $this->owner, 'Otra vez');
                $this->fail('Se canceló dos veces el mismo pedido.');
            } catch (InvalidOrderTransitionException $exception) {
                $this->assertStringContainsString('ya no se puede cancelar', $exception->getMessage());
            }
        }

        $this->assertSame(5, $variant->fresh()->stock);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(1, $coupon->fresh()->used_count);
        $this->assertSame('Cliente no pagó', $order->fresh()->cancel_reason);
        $this->assertSame(1, $order->statusHistories()->count());
    }

    public function test_shipped_and_delivered_orders_can_not_be_cancelled()
    {
        $variant = $this->variant('Camisa', 3);

        foreach ([OrderStatus::Shipped, OrderStatus::Delivered] as $status) {
            $order = $this->order([[$variant, 2]], $status);

            try {
                $this->cancellations->cancel($order, $this->owner, 'Otro');
                $this->fail("Se canceló un pedido {$status->value}.");
            } catch (InvalidOrderTransitionException) {
                $this->assertSame($status, $order->fresh()->status);
            }
        }

        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertSame(0, StockMovement::count());
    }

    // ---- 7. Reactivar ---------------------------------------------------------------------------

    public function test_reactivating_with_stock_reserves_again_and_takes_the_pieces()
    {
        $this->travelTo('2026-10-06 12:00:00');
        $shirt = $this->variant('Camisa', 1);
        $backorder = $this->variant('Pantalón', 0);
        $coupon = Coupon::create(['store_id' => $this->g5->id, 'code' => 'UNO', 'type' => CouponType::Percent, 'value' => 10, 'used_count' => 1]);
        $order = $this->order([[$shirt, 2], [$backorder, 1, true]], extra: ['coupon_id' => $coupon->id, 'expires_at' => now()->subHour()]);

        // Venció: el sistema lo cancela y las piezas regresan.
        $this->cancellations->cancel($order, null, 'Venció el apartado');
        $this->assertSame(3, $shirt->fresh()->stock);
        $this->assertSame(0, $coupon->fresh()->used_count);

        $this->travelTo('2026-10-07 09:00:00');
        $this->reservations->reactivate($order, $this->staff);

        $this->assertSame(OrderStatus::Reserved, $order->status);
        $this->assertNull($order->cancelled_at);
        $this->assertNull($order->cancel_reason);
        $this->assertNull($order->cancelled_by);
        $this->assertTrue($order->expires_at->equalTo(now()->addHours(48)));

        // Descuenta otra vez solo la línea normal.
        $this->assertSame(1, $shirt->fresh()->stock);
        $this->assertSame(0, $backorder->fresh()->stock);

        $movement = StockMovement::where('type', StockMovementType::WebSale->value)->sole();
        $this->assertSame([$shirt->id, -2, $order->id, $this->staff->id], [$movement->product_variant_id, $movement->quantity, $movement->order_id, $movement->user_id]);

        $this->assertSame(1, $coupon->fresh()->used_count);

        $last = $order->statusHistories()->get()->last();
        $this->assertSame([OrderStatus::Cancelled, OrderStatus::Reserved, $this->staff->id], [$last->from_status, $last->to_status, $last->user_id]);
    }

    public function test_reactivating_without_stock_does_nothing_and_says_which_size_is_missing()
    {
        $shirt = $this->variant('Camisa', 0);
        $pants = $this->variant('Pantalón', 0, '32');
        $order = $this->order([[$shirt, 2], [$pants, 1]]);
        $this->cancellations->cancel($order, null, 'Venció el apartado');

        // Mientras estuvo cancelado, alguien más se llevó una camisa: quedan 1 de 2.
        $shirt->update(['stock' => 1]);
        $movements = StockMovement::count();

        try {
            $this->reservations->reactivate($order, $this->staff);
            $this->fail('Se reactivó un pedido sin piezas suficientes.');
        } catch (InvalidOrderTransitionException $exception) {
            $this->assertSame("No se reactivó el pedido {$order->number}: ya no hay piezas suficientes de Camisa (talla M).", $exception->getMessage());
        }

        $order->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(CancelledBy::System, $order->cancelled_by);
        // Ni la talla que faltó ni la que sí había se tocaron.
        $this->assertSame(1, $shirt->fresh()->stock);
        $this->assertSame(1, $pants->fresh()->stock);
        $this->assertSame($movements, StockMovement::count());
        $this->assertSame(1, $order->statusHistories()->count());
    }

    public function test_reactivating_names_every_missing_size_and_a_deleted_one_counts_as_missing()
    {
        $shirt = $this->variant('Camisa', 0);
        $pants = $this->variant('Pantalón', 0, '32');
        $order = $this->order([[$shirt, 1], [$pants, 1]]);
        $this->cancellations->cancel($order, null, 'Venció el apartado');

        $shirt->fresh()->update(['stock' => 0]);
        $pants->delete();

        try {
            $this->reservations->reactivate($order, $this->staff);
            $this->fail('Se reactivó un pedido sin piezas.');
        } catch (InvalidOrderTransitionException $exception) {
            $this->assertStringContainsString('Camisa (talla M) y Pantalón (talla 32)', $exception->getMessage());
        }

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_only_orders_cancelled_by_the_system_and_never_paid_can_be_reactivated()
    {
        $variant = $this->variant('Camisa', 5);
        $byStaff = $this->order([[$variant, 1]]);
        $this->cancellations->cancel($byStaff, $this->staff, 'Cliente no pagó');
        $byCustomer = $this->order([[$variant, 1]]);
        $this->cancellations->cancel($byCustomer, $this->ana, 'Ya no');
        $paidThenSystem = $this->order([[$variant, 1]], OrderStatus::Cancelled, ['cancelled_by' => CancelledBy::System, 'paid_at' => now(), 'cancelled_at' => now()]);
        $reserved = $this->order([[$variant, 1]]);

        foreach ([$byStaff, $byCustomer, $paidThenSystem, $reserved] as $order) {
            $before = $order->fresh()->status;

            try {
                $this->reservations->reactivate($order, $this->owner);
                $this->fail("Se reactivó el pedido {$order->number}.");
            } catch (InvalidOrderTransitionException $exception) {
                $this->assertStringContainsString('no se puede reactivar', $exception->getMessage());
            }

            $this->assertSame($before, $order->fresh()->status);
        }

        $this->panelAs($this->owner);
        $this->detail($byStaff)->assertActionHidden('reactivate');
        $this->detail($paidThenSystem)->assertActionHidden('reactivate');
        $this->detail($reserved)->assertActionHidden('reactivate');
    }

    public function test_the_panel_reactivates_and_reports_the_missing_size()
    {
        $variant = $this->variant('Camisa', 0);
        $ok = $this->order([[$variant, 1]]);
        $short = $this->order([[$variant, 1]]);
        $this->cancellations->cancel($ok, null, 'Venció el apartado');
        $this->cancellations->cancel($short, null, 'Venció el apartado');
        $variant->update(['stock' => 1]);
        $this->panelAs($this->staff);

        $this->detail($ok)->assertActionVisible('reactivate')->callAction('reactivate')->assertHasNoActionErrors();

        $this->assertSame(OrderStatus::Reserved, $ok->fresh()->status);
        $this->assertSame(0, $variant->fresh()->stock);

        // Para el segundo ya no alcanza.
        $this->detail($short)->callAction('reactivate')->assertNotified('No se reactivó el pedido');

        $this->assertSame(OrderStatus::Cancelled, $short->fresh()->status);
        $this->assertSame(0, $variant->fresh()->stock);
    }

    // ---- 8. Dar más tiempo ----------------------------------------------------------------------

    public function test_giving_more_time_moves_the_deadline_and_leaves_history()
    {
        $this->travelTo('2026-10-06 12:00:00');
        $variant = $this->variant('Camisa', 0);
        $order = $this->order([[$variant, 1]], extra: ['expires_at' => '2026-10-07 10:00:00']);

        $this->reservations->extend($order, 24, $this->staff);

        $this->assertSame('2026-10-08 10:00:00', $order->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame(OrderStatus::Reserved, $order->status);

        $this->reservations->extend($order, 48, $this->owner);

        $this->assertSame('2026-10-10 10:00:00', $order->expires_at->format('Y-m-d H:i:s'));

        $history = $order->statusHistories()->get();

        $this->assertCount(2, $history);
        $this->assertSame([OrderStatus::Reserved, OrderStatus::Reserved, $this->staff->id], [$history[0]->from_status, $history[0]->to_status, $history[0]->user_id]);
        $this->assertSame('Se dieron 24 horas más para pagar. Nueva fecha límite: 08/10/2026 10:00.', $history[0]->note);
        $this->assertSame($this->owner->id, $history[1]->user_id);
        $this->assertSame('Se dieron 48 horas más para pagar. Nueva fecha límite: 10/10/2026 10:00.', $history[1]->note);

        // No mueve piezas.
        $this->assertSame(0, StockMovement::count());
    }

    public function test_more_time_on_a_deadline_already_past_counts_from_now()
    {
        $this->travelTo('2026-10-06 12:00:00');
        $order = $this->order([[$this->variant('Camisa', 0), 1]], extra: ['expires_at' => '2026-10-05 08:00:00']);

        $this->reservations->extend($order, 24, $this->staff);

        $this->assertSame('2026-10-07 12:00:00', $order->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_more_time_is_only_24_or_48_hours_and_only_for_reserved_orders()
    {
        $variant = $this->variant('Camisa', 0);
        $reserved = $this->order([[$variant, 1]]);
        $deadline = $reserved->expires_at->copy();

        foreach ([0, 12, 72, -24] as $hours) {
            try {
                $this->reservations->extend($reserved, $hours, $this->staff);
                $this->fail("Se dieron {$hours} horas.");
            } catch (InvalidOrderTransitionException) {
                $this->assertTrue($reserved->fresh()->expires_at->equalTo($deadline));
            }
        }

        foreach ([OrderStatus::Paid, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Cancelled] as $status) {
            $order = $this->order([[$variant, 1]], $status);

            try {
                $this->reservations->extend($order, 24, $this->staff);
                $this->fail("Se dio más tiempo a un pedido {$status->value}.");
            } catch (InvalidOrderTransitionException) {
                $this->assertSame(0, $order->statusHistories()->count());
            }
        }
    }

    public function test_the_panel_gives_more_time_to_reserved_orders_only()
    {
        $this->travelTo('2026-10-06 12:00:00');
        $variant = $this->variant('Camisa', 0);
        $reserved = $this->order([[$variant, 1]], extra: ['expires_at' => '2026-10-07 10:00:00']);
        $paid = $this->order([[$variant, 1]], OrderStatus::Paid);
        $this->panelAs($this->staff);

        $this->detail($paid)->assertActionHidden('extend');

        $this->detail($reserved)
            ->assertActionVisible('extend')
            ->callAction('extend', data: ['hours' => 48])
            ->assertHasNoActionErrors();

        $this->assertSame('2026-10-09 10:00:00', $reserved->fresh()->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->staff->id, $reserved->statusHistories()->sole()->user_id);

        $this->detail($reserved)->assertSee('Anotación (Apartado)')->assertSee('Se dieron 48 horas más para pagar.');
    }

    // ---- Panel: cancelar ------------------------------------------------------------------------

    public function test_the_cancel_action_needs_a_reason_and_keeps_the_note()
    {
        $variant = $this->variant('Camisa', 3);
        $order = $this->order([[$variant, 2]]);
        $this->panelAs($this->staff);

        $this->detail($order)
            ->callAction('cancel', data: ['reason' => null])
            ->assertHasActionErrors(['reason' => 'required']);

        $this->assertSame(OrderStatus::Reserved, $order->fresh()->status);

        $this->detail($order)
            ->callAction('cancel', data: ['reason' => 'Cliente no pagó', 'note' => ' No contestó el WhatsApp '])
            ->assertHasNoActionErrors();

        $order->refresh();

        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('Cliente no pagó: No contestó el WhatsApp', $order->cancel_reason);
        $this->assertSame(CancelledBy::Admin, $order->cancelled_by);
        // La casilla de regresar piezas viene activa.
        $this->assertSame(5, $variant->fresh()->stock);

        $this->detail($order)->assertActionHidden('cancel')->assertSee('Cliente no pagó: No contestó el WhatsApp');
    }

    public function test_the_cancel_action_can_leave_the_pieces_out_of_the_inventory()
    {
        $variant = $this->variant('Camisa', 3);
        $order = $this->order([[$variant, 2]]);
        $this->panelAs($this->staff);

        $this->detail($order)
            ->callAction('cancel', data: ['reason' => 'Producto dañado', 'restock' => false])
            ->assertHasNoActionErrors();

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertSame(0, StockMovement::count());
    }

    public function test_cancelling_a_paid_order_marks_the_refund_or_leaves_it_pending()
    {
        $this->travelTo('2026-10-06 12:00:00');
        $variant = $this->variant('Camisa', 3);
        $refunded = $this->order([[$variant, 1]], OrderStatus::Paid);
        $pending = $this->order([[$variant, 1]], OrderStatus::Paid);
        $neverPaid = $this->order([[$variant, 1]]);
        $this->panelAs($this->owner);

        $this->detail($refunded)->callAction('cancel', data: ['reason' => 'Cliente pidió cancelar', 'refunded' => true])->assertHasNoActionErrors();
        $this->detail($pending)->callAction('cancel', data: ['reason' => 'Sin existencia real'])->assertHasNoActionErrors();
        $this->detail($neverPaid)->callAction('cancel', data: ['reason' => 'Cliente no pagó'])->assertHasNoActionErrors();

        $this->assertTrue($refunded->fresh()->refunded_at->equalTo(now()));
        $this->assertFalse($refunded->fresh()->refundPending());
        $this->assertNull($pending->fresh()->refunded_at);
        $this->assertTrue($pending->fresh()->refundPending());
        $this->assertFalse($neverPaid->fresh()->refundPending());

        // Marca y filtro en la lista.
        Livewire::test(ListOrders::class)
            ->assertTableColumnStateSet('marks', ['Reembolso pendiente'], $pending->fresh())
            ->assertTableColumnStateSet('marks', null, $refunded->fresh())
            ->assertTableColumnStateSet('marks', null, $neverPaid->fresh())
            ->filterTable('refund_pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$refunded, $neverPaid]);

        // El dueño registra el reembolso después y la marca se va.
        $this->detail($pending)->assertSee('Reembolso pendiente')->callAction('markRefunded')->assertHasNoActionErrors();

        $this->assertFalse($pending->fresh()->refundPending());
        $this->assertSame('Reembolso realizado.', $pending->statusHistories()->get()->last()->note);
        $this->detail($pending->fresh())->assertActionHidden('markRefunded');
    }

    public function test_only_the_owner_registers_a_refund()
    {
        $pending = $this->order([[$this->variant('Camisa', 3), 1]], OrderStatus::Paid);
        $this->cancellations->cancel($pending, $this->owner, 'Otro');

        $this->panelAs($this->staff);
        $this->detail($pending)->assertActionHidden('markRefunded');

        $this->panelAs($this->owner);
        $this->detail($pending)->assertActionVisible('markRefunded');
    }

    public function test_a_stale_cancel_from_the_panel_shows_a_notice_instead_of_returning_stock_again()
    {
        $variant = $this->variant('Camisa', 3);
        $order = $this->order([[$variant, 2]]);
        $this->panelAs($this->staff);

        // Dos pestañas abiertas con el mismo pedido apartado.
        $first = $this->detail($order);
        $second = $this->detail($order);

        $first->callAction('cancel', data: ['reason' => 'Cliente no pagó'])->assertHasNoActionErrors();
        $second->callAction('cancel', data: ['reason' => 'Cliente no pagó']);

        $this->assertSame(5, $variant->fresh()->stock);
        $this->assertSame(1, StockMovement::count());
    }
}
