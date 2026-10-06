<?php

namespace Tests\Feature;

use App\Console\Commands\ExpireUnpaidOrders;
use App\Enums\CancelledBy;
use App\Enums\CouponType;
use App\Enums\OrderStatus;
use App\Enums\SizeType;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
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
use App\Services\OrderService;
use App\Services\OrderStatusService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ReservationExpiryTest extends TestCase
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

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);
        $this->ana = Customer::factory()->create(['name' => 'Ana Pérez']);
    }

    private function variant(Store $store, string $name, int $stock = 10, array $extra = []): ProductVariant
    {
        $category = Category::firstOrCreate(
            ['store_id' => $store->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        return $category->products()
            ->create(['store_id' => $store->id, 'name' => $name, 'slug' => str($name)->slug(), 'price' => 500, ...$extra])
            ->variants()->create(['size' => 'M', 'stock' => $stock]);
    }

    /** Respuesta de apartar por la tienda en línea lo que se indique: [talla, cantidad]... */
    private function checkout(array $lines, ?Customer $customer = null, string $host = self::COPPERS)
    {
        $this->actingAs($customer ?? $this->ana, 'customer');

        foreach ($lines as [$variant, $quantity]) {
            $this->post("{$host}/carrito", ['variant_id' => $variant->id, 'quantity' => $quantity])->assertSessionHasNoErrors();
        }

        return $this->post("{$host}/checkout", ['delivery_method' => 'recoger', 'phone' => '755 123 4567']);
    }

    /** Aparta de verdad (descuenta stock) y devuelve el pedido, con la fecha límite que se pida. */
    private function reserve(array $lines, ?Customer $customer = null, string $host = self::COPPERS, ?int $expiresInHours = null): Order
    {
        $this->checkout($lines, $customer, $host)->assertSessionHasNoErrors();

        $order = Order::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();

        if ($expiresInHours !== null) {
            $order->update(['expires_at' => now()->addHours($expiresInHours)]);
        }

        return $order;
    }

    private function stock(ProductVariant $variant): int
    {
        return (int) $variant->fresh()->stock;
    }

    private function returned(Order $order): int
    {
        return (int) StockMovement::where('order_id', $order->id)->where('type', StockMovementType::Cancellation->value)->sum('quantity');
    }

    // ---- 1. Un pedido vencido se cancela, regresa stock y queda cancelado por el sistema ----------

    public function test_an_expired_order_is_cancelled_by_the_system_and_its_stock_returns()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 5);
        $special = $this->variant($this->coppers, 'Chamarra', 1, ['allow_backorder' => true]);
        $coupon = Coupon::create(['store_id' => $this->coppers->id, 'code' => 'PROMO', 'type' => CouponType::Fixed, 'value' => 50, 'is_active' => true]);

        $this->actingAs($this->ana, 'customer');
        $this->post(self::COPPERS.'/carrito', ['variant_id' => $shirt->id, 'quantity' => 2]);
        $this->post(self::COPPERS.'/carrito/cupon', ['code' => 'PROMO'])->assertSessionHasNoErrors();
        $order = $this->reserve([[$special, 3]], expiresInHours: -1);

        $this->assertSame(3, $this->stock($shirt));
        $this->assertSame(0, $this->stock($special));
        $this->assertSame(1, $coupon->fresh()->used_count);

        Log::spy();

        $this->artisan('orders:expire-unpaid')
            ->expectsOutputToContain("Cancelado: {$order->number}")
            ->expectsOutputToContain('1 pedidos cancelados')
            ->assertSuccessful();

        Log::shouldHaveReceived('info')->once()->with('orders:expire-unpaid: 1 pedidos cancelados por vencer el plazo de pago.');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(CancelledBy::System, $order->cancelled_by);
        $this->assertSame('Venció el plazo de pago', $order->cancel_reason);
        $this->assertSame(ExpireUnpaidOrders::REASON, $order->cancel_reason);
        $this->assertNotNull($order->cancelled_at);

        // Regresan las piezas apartadas; las 2 sobre pedido de la chamarra nunca se descontaron.
        $this->assertSame(5, $this->stock($shirt));
        $this->assertSame(1, $this->stock($special));
        $this->assertSame(3, $this->returned($order));
        $this->assertSame(0, $coupon->fresh()->used_count);

        $row = $order->statusHistories()->get()->last();
        $this->assertSame(OrderStatus::Reserved, $row->from_status);
        $this->assertSame(OrderStatus::Cancelled, $row->to_status);
        $this->assertNull($row->user_id);
        $this->assertNull($row->customer_id);
        $this->assertSame('Venció el plazo de pago', $row->note);

        // Lo canceló el sistema sin pago: se puede reactivar desde el panel.
        $this->assertTrue(OrderStatusService::canReactivate($order));
    }

    // ---- 2. Lo que no está vencido, o ya está pagado o cancelado, no se toca ----------------------

    public function test_orders_not_expired_paid_or_already_cancelled_are_not_touched()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 10);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $luis = Customer::factory()->create();
        $marta = Customer::factory()->create();

        $onTime = $this->reserve([[$shirt, 1]], expiresInHours: 1);

        // Pagado: aunque su fecha límite ya pasó.
        $paid = $this->reserve([[$shirt, 1]], expiresInHours: -5);
        app(OrderStatusService::class)->transition($paid, OrderStatus::Paid, $owner, data: ['payment_reference' => 'ABC']);

        // Enviado y entregado, también con la fecha vieja.
        $shipped = $this->reserve([[$shirt, 1]], $luis, expiresInHours: -5);
        app(OrderStatusService::class)->transition($shipped, OrderStatus::Paid, $owner);
        app(OrderStatusService::class)->transition($shipped, OrderStatus::Shipped, $owner);

        // Ya cancelado por el cliente (su stock ya regresó).
        $cancelled = $this->reserve([[$shirt, 1]], $luis, expiresInHours: -5);
        app(OrderCancellationService::class)->cancel($cancelled, $luis, 'Ya no lo quiero');

        // Vencido, pero el personal le dio más tiempo.
        $extended = $this->reserve([[$shirt, 1]], $marta, expiresInHours: -5);
        app(OrderReservationService::class)->extend($extended, 24, $owner);

        $this->assertSame(6, $this->stock($shirt));

        $this->artisan('orders:expire-unpaid')->expectsOutputToContain('0 pedidos cancelados')->assertSuccessful();

        $this->assertSame(OrderStatus::Reserved, $onTime->fresh()->status);
        $this->assertSame(OrderStatus::Paid, $paid->fresh()->status);
        $this->assertSame(OrderStatus::Shipped, $shipped->fresh()->status);
        $this->assertSame(OrderStatus::Reserved, $extended->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame(CancelledBy::Customer, $cancelled->fresh()->cancelled_by);
        $this->assertSame('Ya no lo quiero', $cancelled->fresh()->cancel_reason);

        $this->assertSame(6, $this->stock($shirt));
        $this->assertSame(1, StockMovement::where('type', StockMovementType::Cancellation->value)->count());
    }

    public function test_it_expires_orders_of_every_store()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 5);
        $ball = $this->variant($this->g5, 'Balón', 5);

        $a = $this->reserve([[$shirt, 1]], expiresInHours: -1);
        $b = $this->reserve([[$ball, 2]], host: self::G5, expiresInHours: -1);

        $this->artisan('orders:expire-unpaid')->expectsOutputToContain('2 pedidos cancelados')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, Order::withoutGlobalScopes()->find($a->id)->status);
        $this->assertSame(OrderStatus::Cancelled, Order::withoutGlobalScopes()->find($b->id)->status);
        $this->assertSame(5, $this->stock($shirt));
        $this->assertSame(5, $this->stock($ball));
    }

    // ---- 3. Correrlo dos veces no regresa el stock doble ------------------------------------------

    public function test_running_the_command_twice_does_not_return_the_stock_twice()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 5);
        $order = $this->reserve([[$shirt, 2]], expiresInHours: -1);

        $this->artisan('orders:expire-unpaid')->expectsOutputToContain('1 pedidos cancelados')->assertSuccessful();
        $this->artisan('orders:expire-unpaid')->expectsOutputToContain('0 pedidos cancelados')->assertSuccessful();

        $this->assertSame(5, $this->stock($shirt));
        $this->assertSame(2, $this->returned($order));
        $this->assertSame(1, StockMovement::where('order_id', $order->id)->where('type', StockMovementType::Cancellation->value)->count());
        $this->assertSame(1, $order->statusHistories()->where('to_status', OrderStatus::Cancelled->value)->count());
    }

    // ---- 4. Si un pedido falla, los demás se cancelan igual ---------------------------------------

    public function test_if_one_order_fails_the_others_are_still_cancelled()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 10);
        $pants = $this->variant($this->coppers, 'Pantalón', 10);
        $luis = Customer::factory()->create();
        $marta = Customer::factory()->create();

        $first = $this->reserve([[$shirt, 1]], expiresInHours: -3);
        $broken = $this->reserve([[$shirt, 2], [$pants, 2]], $luis, expiresInHours: -2);
        $last = $this->reserve([[$pants, 3]], $marta, expiresInHours: -1);

        $this->assertSame(7, $this->stock($shirt));
        $this->assertSame(5, $this->stock($pants));

        // El pedido de en medio truena al regresar su segunda línea, ya con la primera regresada.
        StockMovement::creating(function (StockMovement $movement) use ($broken, $pants) {
            if ($movement->order_id === $broken->id && $movement->product_variant_id === $pants->id) {
                throw new RuntimeException('Falla simulada');
            }
        });

        Log::spy();

        $this->artisan('orders:expire-unpaid')
            ->expectsOutputToContain("No se pudo cancelar el pedido {$broken->number}: Falla simulada")
            ->expectsOutputToContain('2 pedidos cancelados')
            ->assertFailed();

        Log::shouldHaveReceived('info')->once()->with('orders:expire-unpaid: 2 pedidos cancelados por vencer el plazo de pago, 1 con error.');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, $broken->number))->atLeast()->once();

        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $last->fresh()->status);

        // El que falló queda intacto: sigue apartado y su transacción se deshizo completa.
        $broken->refresh();
        $this->assertSame(OrderStatus::Reserved, $broken->status);
        $this->assertNull($broken->cancelled_at);
        $this->assertSame(0, $this->returned($broken));
        $this->assertCount(1, $broken->statusHistories()->get());

        $this->assertSame(8, $this->stock($shirt));
        $this->assertSame(8, $this->stock($pants));

        // Sin la falla, la siguiente corrida lo cancela.
        StockMovement::flushEventListeners();
        $this->artisan('orders:expire-unpaid')->expectsOutputToContain('1 pedidos cancelados')->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, $broken->fresh()->status);
        $this->assertSame(10, $this->stock($shirt));
        $this->assertSame(10, $this->stock($pants));
    }

    // ---- --dry-run --------------------------------------------------------------------------------

    public function test_dry_run_only_lists_what_it_would_cancel()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 5);
        $expired = $this->reserve([[$shirt, 2]], expiresInHours: -1);
        $onTime = $this->reserve([[$shirt, 1]], Customer::factory()->create(), expiresInHours: 5);

        $this->artisan('orders:expire-unpaid --dry-run')
            ->expectsOutputToContain($expired->number)
            ->expectsOutputToContain('Se cancelarían 1 pedidos. No se tocó nada (--dry-run).')
            ->doesntExpectOutputToContain($onTime->number)
            ->assertSuccessful();

        $this->assertSame(OrderStatus::Reserved, $expired->fresh()->status);
        $this->assertSame(2, $this->stock($shirt));
        $this->assertSame(0, StockMovement::where('type', StockMovementType::Cancellation->value)->count());
        $this->assertCount(1, $expired->statusHistories()->get());
    }

    public function test_it_is_scheduled_every_ten_minutes_without_overlapping()
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command, 'orders:expire-unpaid'));

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    // ---- 5. Con 3 apartados no se crea el cuarto; con 2 sí ----------------------------------------

    public function test_a_customer_with_three_reservations_can_not_place_a_fourth()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 10);
        $message = "Ya tienes 3 pedidos apartados sin pagar en Copper's. Paga o cancela alguno para poder apartar otro.";

        $this->reserve([[$shirt, 1]]);
        $this->reserve([[$shirt, 1]]);

        // Con 2 todavía puede: ni aviso ni rechazo.
        $this->assertNull(app(OrderService::class)->reservationLimitMessage($this->coppers, $this->ana));
        $third = $this->reserve([[$shirt, 1]]);
        $this->assertSame(3, Order::count());
        $this->assertSame(7, $this->stock($shirt));

        // El cuarto se rechaza: no hay pedido, no se toca el stock y el carrito se queda.
        $this->checkout([[$shirt, 2]])
            ->assertRedirect(self::COPPERS.'/checkout')
            ->assertSessionHasErrors(['reservations' => $message]);

        $this->assertSame(3, Order::count());
        $this->assertSame(7, $this->stock($shirt));

        // /checkout muestra el aviso antes del botón.
        $this->get(self::COPPERS.'/checkout')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('checkout')
            ->where('reservationLimit', $message)
            ->where('cart.count', 2));

        // Al cancelar uno (o al pagarlo) vuelve a poder apartar.
        app(OrderCancellationService::class)->cancel($third, $this->ana);
        $this->get(self::COPPERS.'/checkout')->assertInertia(fn (Assert $page) => $page->where('reservationLimit', null));
        $this->actingAs($this->ana, 'customer')
            ->post(self::COPPERS.'/checkout', ['delivery_method' => 'recoger', 'phone' => '755 123 4567'])
            ->assertSessionHasNoErrors();
        $this->assertSame(4, Order::count());
        $this->assertSame(6, $this->stock($shirt));

        // Ana vuelve a tener 3, pero otro cliente no se ve afectado.
        $this->reserve([[$shirt, 1]], Customer::factory()->create());
        $this->assertSame(5, Order::count());
    }

    public function test_paid_orders_do_not_count_and_the_limit_comes_from_config()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 10);
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        foreach (range(1, 3) as $ignored) {
            $paid = $this->reserve([[$shirt, 1]]);
            app(OrderStatusService::class)->transition($paid, OrderStatus::Paid, $owner);
        }

        $this->reserve([[$shirt, 1]]);
        $this->assertSame(4, Order::count());

        config(['shop.max_open_reservations' => 1]);

        $this->checkout([[$shirt, 1]])->assertSessionHasErrors([
            'reservations' => "Ya tienes 1 pedidos apartados sin pagar en Copper's. Paga o cancela alguno para poder apartar otro.",
        ]);
        $this->assertSame(4, Order::count());
    }

    // ---- 6. Los apartados de la otra tienda no cuentan -------------------------------------------

    public function test_reservations_of_the_other_store_do_not_count()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 10);
        $ball = $this->variant($this->g5, 'Balón', 10);

        foreach (range(1, 3) as $ignored) {
            $this->reserve([[$ball, 1]], host: self::G5);
        }

        // En G5 ya llegó al límite; en Copper's aparta sin problema.
        $this->assertNotNull(app(OrderService::class)->reservationLimitMessage($this->g5, $this->ana));
        $this->assertNull(app(OrderService::class)->reservationLimitMessage($this->coppers, $this->ana));

        $this->checkout([[$shirt, 1]])->assertSessionHasNoErrors();
        $this->assertSame(1, Order::withoutGlobalScopes()->where('store_id', $this->coppers->id)->count());

        $this->checkout([[$ball, 1]], host: self::G5)->assertSessionHasErrors([
            'reservations' => 'Ya tienes 3 pedidos apartados sin pagar en Sports G5. Paga o cancela alguno para poder apartar otro.',
        ]);
        $this->assertSame(3, Order::withoutGlobalScopes()->where('store_id', $this->g5->id)->count());
    }

    // ---- Etiqueta «Vence en X horas» --------------------------------------------------------------

    public function test_reservations_with_less_than_twelve_hours_show_the_expiring_label()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 10);
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $soon = $this->reserve([[$shirt, 1]]);
        $soon->update(['expires_at' => now()->addHours(5)->addMinutes(30)]);
        $far = $this->reserve([[$shirt, 1]], expiresInHours: 13);
        $paid = $this->reserve([[$shirt, 1]], expiresInHours: 2);
        app(OrderStatusService::class)->transition($paid, OrderStatus::Paid, $owner);

        $this->assertSame('Vence en 5 horas', $soon->fresh()->expiringLabel());
        $this->assertNull($far->fresh()->expiringLabel());
        $this->assertNull($paid->fresh()->expiringLabel());

        $label = fn (int $minutes) => (new Order)->forceFill(['status' => OrderStatus::Reserved, 'expires_at' => now()->addMinutes($minutes)->addSeconds(30)])->expiringLabel();
        $this->assertNull($label(12 * 60));
        $this->assertSame('Vence en 11 horas', $label(12 * 60 - 1));
        $this->assertSame('Vence en 2 horas', $label(120));
        $this->assertSame('Vence en 1 hora', $label(119));
        $this->assertSame('Vence en 1 hora', $label(60));
        $this->assertSame('Vence en menos de 1 hora', $label(59));
        $this->assertSame('Venció el plazo de pago', $label(-5));

        $this->actingAs($this->ana, 'customer');

        $this->get(self::COPPERS.'/mi-cuenta/pedidos')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('orders', fn ($orders) => collect($orders)->pluck('expiring_label', 'number')->all() === [
                $paid->number => null,
                $far->number => null,
                $soon->number => 'Vence en 5 horas',
            ]));

        $this->get(self::COPPERS."/mi-cuenta/pedidos/{$soon->number}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('order.expiring_label', 'Vence en 5 horas'));
        $this->get(self::COPPERS."/mi-cuenta/pedidos/{$far->number}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('order.expiring_label', null));
    }
}
