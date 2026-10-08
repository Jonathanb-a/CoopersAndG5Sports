<?php

namespace Tests\Feature;

use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\SizeType;
use App\Enums\StockMovementType;
use App\Enums\SystemActor;
use App\Enums\UserRole;
use App\Exceptions\CrossStoreAccessException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\CancellationRequestService;
use App\Services\OrderCancellationService;
use App\Services\OrderReservationService;
use App\Services\OrderStatusService;
use App\Services\StockService;
use App\Support\StoreGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Candado de tienda (StoreGuard) de los servicios que escriben: quién puede tocar qué pedido. */
class StoreGuardTest extends TestCase
{
    use RefreshDatabase;

    private Store $coppers;

    private Store $g5;

    private User $coppersStaff;

    private User $g5Staff;

    private User $owner;

    private Customer $ana;

    private Customer $beto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);
        $this->coppersStaff = User::factory()->create(['role' => UserRole::Staff]);
        $this->coppersStaff->stores()->attach($this->coppers);
        $this->g5Staff = User::factory()->create(['role' => UserRole::Staff]);
        $this->g5Staff->stores()->attach($this->g5);
        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->ana = Customer::factory()->create();
        $this->beto = Customer::factory()->create();
    }

    private function variant(Store $store, int $stock = 5): ProductVariant
    {
        $category = Category::withoutGlobalScopes()->firstOrCreate(
            ['store_id' => $store->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        return $category->products()
            ->create(['store_id' => $store->id, 'name' => "Playera {$store->slug}", 'slug' => "playera-{$store->slug}", 'price' => 500])
            ->variants()->create(['size' => 'M', 'stock' => $stock]);
    }

    private function order(Store $store, ?Customer $customer = null, OrderStatus $status = OrderStatus::Reserved, array $extra = []): Order
    {
        static $sequence = 0;

        return Order::create([
            'store_id' => $store->id,
            'customer_id' => ($customer ?? $this->ana)->id,
            'number' => sprintf('%s-%05d', strtoupper($store->slug), ++$sequence),
            'channel' => OrderChannel::Web,
            'status' => $status,
            'delivery_method' => DeliveryMethod::Pickup,
            'subtotal' => 500,
            'total' => 500,
            'expires_at' => now()->addDay(),
            ...$extra,
        ]);
    }

    private function refuses(callable $action, string $message = StoreGuard::MESSAGE): void
    {
        try {
            $action();
        } catch (CrossStoreAccessException $exception) {
            $this->assertSame($message, $exception->getMessage());

            return;
        }

        $this->fail('Debió lanzar CrossStoreAccessException.');
    }

    // ---- Staff de otra tienda ---------------------------------------------------------------------

    public function test_staff_of_g5_can_not_transition_cancel_or_extend_a_coppers_order()
    {
        $order = $this->order($this->coppers);

        $this->refuses(fn () => app(OrderStatusService::class)->transition($order, OrderStatus::Paid, $this->g5Staff));
        $this->refuses(fn () => app(OrderCancellationService::class)->cancel($order, $this->g5Staff, 'x'));
        $this->refuses(fn () => app(OrderReservationService::class)->extend($order, 24, $this->g5Staff));
        $this->refuses(fn () => app(OrderStatusService::class)->annotate($order, $this->g5Staff, 'x'));

        $paid = $this->order($this->coppers, status: OrderStatus::Paid, extra: ['cancellation_requested_at' => now()]);
        $this->refuses(fn () => app(CancellationRequestService::class)->reject($paid, $this->g5Staff));

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::Reserved, $fresh->status);
        $this->assertCount(0, $fresh->statusHistories);
        $this->assertNotNull($paid->fresh()->cancellation_requested_at);
    }

    public function test_the_refusal_does_not_reveal_the_order()
    {
        $order = $this->order($this->coppers);

        try {
            app(OrderCancellationService::class)->cancel($order, $this->g5Staff);
            $this->fail('Debió negarse.');
        } catch (CrossStoreAccessException $exception) {
            $this->assertStringNotContainsString($order->number, $exception->getMessage());
        }
    }

    public function test_staff_of_the_store_and_the_owner_keep_working()
    {
        $transitions = app(OrderStatusService::class);

        $a = $this->order($this->coppers);
        $transitions->transition($a, OrderStatus::Paid, $this->coppersStaff);
        $this->assertSame(OrderStatus::Paid, $a->fresh()->status);

        $b = $this->order($this->g5);
        app(OrderReservationService::class)->extend($b, 24, $this->owner);
        app(OrderCancellationService::class)->cancel($b, $this->owner, 'ok');
        $this->assertSame(OrderStatus::Cancelled, $b->fresh()->status);

        $c = $this->order($this->coppers, status: OrderStatus::Paid, extra: ['cancellation_requested_at' => now()]);
        app(CancellationRequestService::class)->reject($c, $this->coppersStaff);
        $this->assertNull($c->fresh()->cancellation_requested_at);
    }

    // ---- Clientes ---------------------------------------------------------------------------------

    public function test_a_coppers_customer_can_not_cancel_or_request_cancellation_of_a_g5_order()
    {
        $g5Order = $this->order($this->g5, $this->ana);
        $g5Paid = $this->order($this->g5, $this->ana, OrderStatus::Paid);

        // Ana está comprando en Copper's: su pedido de G5 no se toca desde esa tienda.
        $this->requestingStore($this->coppers);

        $this->refuses(fn () => app(OrderCancellationService::class)->cancel($g5Order, $this->ana));
        $this->refuses(fn () => app(CancellationRequestService::class)->request($g5Paid, $this->ana, 'x'));

        $this->assertSame(OrderStatus::Reserved, $g5Order->fresh()->status);
        $this->assertNull($g5Paid->fresh()->cancellation_requested_at);
    }

    public function test_a_customer_can_not_touch_the_order_of_another_customer()
    {
        $order = $this->order($this->coppers, $this->beto);
        $this->requestingStore($this->coppers);

        $this->refuses(fn () => app(OrderCancellationService::class)->cancel($order, $this->ana));
        $this->assertSame(OrderStatus::Reserved, $order->fresh()->status);
    }

    public function test_a_customer_without_a_store_request_is_refused()
    {
        $order = $this->order($this->coppers, $this->ana);

        $this->refuses(fn () => app(OrderCancellationService::class)->cancel($order, $this->ana));
    }

    public function test_the_customer_owner_of_the_order_in_its_store_can_cancel_and_request()
    {
        $this->requestingStore($this->coppers);

        $reserved = $this->order($this->coppers, $this->ana);
        app(OrderCancellationService::class)->cancel($reserved, $this->ana, 'Ya no');
        $this->assertSame(OrderStatus::Cancelled, $reserved->fresh()->status);

        $paid = $this->order($this->coppers, $this->ana, OrderStatus::Paid);
        app(CancellationRequestService::class)->request($paid, $this->ana, 'Ya no');
        $this->assertNotNull($paid->fresh()->cancellation_requested_at);
    }

    // ---- Sistema ----------------------------------------------------------------------------------

    public function test_the_expiry_command_still_cancels_orders_of_every_store_as_the_system()
    {
        $coppers = $this->order($this->coppers, extra: ['expires_at' => now()->subHour()]);
        $g5 = $this->order($this->g5, extra: ['expires_at' => now()->subHour()]);

        $this->artisan('orders:expire-unpaid')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $coppers->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $g5->fresh()->status);
    }

    public function test_a_null_actor_is_refused_and_only_the_explicit_system_actor_passes()
    {
        $order = $this->order($this->coppers);

        // null no es nadie: el candado lo rechaza, y los servicios ni siquiera lo aceptan como tipo.
        $this->refuses(fn () => StoreGuard::authorize($order, null));
        $this->expectException(\TypeError::class);

        try {
            app(OrderStatusService::class)->transition($order, OrderStatus::Cancelled, null, 'Venció');
        } finally {
            $this->assertSame(OrderStatus::Reserved, $order->fresh()->status);
        }
    }

    public function test_the_explicit_system_actor_passes_and_shows_as_system()
    {
        $order = $this->order($this->coppers);

        app(OrderStatusService::class)->transition($order, OrderStatus::Cancelled, SystemActor::System, 'Venció');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame('Sistema', $order->statusHistories()->sole()->actorLabel());
    }

    // ---- StockService -----------------------------------------------------------------------------

    public function test_stock_change_with_an_order_of_another_store_fails_and_changes_nothing()
    {
        $variant = $this->variant($this->coppers, 5);
        $g5Order = $this->order($this->g5);

        $this->refuses(fn () => app(StockService::class)->change($variant, StockMovementType::Cancellation, 1, null, 'x', $g5Order), 'La pieza y el pedido son de tiendas distintas.');

        $this->assertSame(5, $variant->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_stock_change_with_an_order_of_the_same_store_or_without_order_works()
    {
        $variant = $this->variant($this->coppers, 5);
        $order = $this->order($this->coppers);

        app(StockService::class)->change($variant, StockMovementType::Cancellation, 1, null, 'con pedido', $order);
        app(StockService::class)->change($variant, StockMovementType::Entry, 2, null, 'sin pedido');

        $this->assertSame(8, $variant->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 2);
    }
}
