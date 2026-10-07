<?php

namespace Tests\Feature;

use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SalesPeriod;
use App\Enums\SizeType;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransitionException;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Widgets\SalesStats;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderCancellationService;
use App\Services\OrderDeliveryService;
use App\Services\OrderReservationService;
use App\Services\OrderStatusService;
use App\Services\OverdueCashOrders;
use App\Services\SalesReport;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Pedidos en efectivo: vencimiento, fecha de entrega, aviso de «sin entregar» y conteo como venta. */
class CashExpiryTest extends TestCase
{
    use RefreshDatabase;

    private const COPPERS = 'http://coppers.localhost';

    private Store $coppers;

    private Customer $ana;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-10-07 10:00:00');

        $this->coppers = Store::create([
            'name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers', 'local_shipping_cost' => 80,
        ]);
        $this->ana = Customer::factory()->create(['name' => 'Ana Pérez']);
        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->staff = User::factory()->create(['role' => UserRole::Staff]);
        $this->staff->stores()->attach($this->coppers->id);

        config(['services.telegram.bot_token' => '123:TOKEN', 'services.telegram.owner_chat_id' => '99887766']);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    private function variant(int $stock = 10): ProductVariant
    {
        $category = Category::firstOrCreate(
            ['store_id' => $this->coppers->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        $count = ProductVariant::count() + 1;

        return $category->products()
            ->create(['store_id' => $this->coppers->id, 'name' => "Camisa {$count}", 'slug' => "camisa-{$count}", 'price' => 500])
            ->variants()->create(['size' => 'M', 'stock' => $stock]);
    }

    /** Aparta por la tienda en línea (recoger en tienda salvo que se diga otra cosa) y devuelve el pedido. */
    private function place(string $payment, array $extra = [], ?ProductVariant $variant = null): Order
    {
        $this->actingAs($this->ana, 'customer');
        $this->post(self::COPPERS.'/carrito', ['variant_id' => ($variant ?? $this->variant())->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post(self::COPPERS.'/checkout', ['delivery_method' => 'recoger', 'payment_method' => $payment, 'phone' => '755 123 4567', ...$extra])
            ->assertSessionHasNoErrors();

        return Order::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function local(): array
    {
        return ['delivery_method' => 'local', 'local' => ['name' => 'Ana Pérez', 'point' => 'Hotel Sol, lobby']];
    }

    private function confirm(Order $order): Order
    {
        return app(OrderStatusService::class)->transition($order, OrderStatus::Paid, $this->owner, data: $order->paysInCash() ? [] : ['payment_reference' => 'ABC']);
    }

    private function schedule(Order $order, string $date): Order
    {
        return app(OrderDeliveryService::class)->schedule($order, $date, 'Entre 4 y 6 pm', $this->owner);
    }

    private function runCommand(string $expected): void
    {
        $this->artisan('orders:expire-unpaid')->expectsOutputToContain($expected)->assertSuccessful();
    }

    private function panel(User $user): void
    {
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($this->coppers);
    }

    /** Textos mandados a Telegram, en orden. */
    private function telegram(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0]['text'])->all();
    }

    private function overdueNotices(User $user): int
    {
        return $user->notifications()->get()->filter(fn ($n) => $n->data['title'] === OverdueCashOrders::TITLE)->count();
    }

    // ---- 1. Vence a las 72 h ----------------------------------------------------------------------

    public function test_the_hours_and_days_come_from_the_shop_config()
    {
        $this->assertSame(72, config('shop.cash_reserve_hours'));
        $this->assertSame(7, config('shop.cash_reserve_max_days'));
        $this->assertSame(48, config('shop.reserve_hours'));
    }

    public function test_a_cash_order_expires_after_72_hours_like_the_others_and_transfer_stays_at_48()
    {
        $cashVariant = $this->variant();
        $transferVariant = $this->variant();
        $cash = $this->place('cash', variant: $cashVariant);
        $transfer = $this->place('transfer', variant: $transferVariant);

        $this->assertSame('2026-10-10 10:00:00', $cash->expires_at->toDateTimeString());
        $this->assertSame('2026-10-09 10:00:00', $transfer->expires_at->toDateTimeString());

        // 71 h: ninguno de efectivo; la transferencia (48 h) ya venció.
        $this->travelTo('2026-10-10 09:00:00');
        $this->runCommand('1 pedidos cancelados');
        $this->assertSame(OrderStatus::Reserved, $cash->fresh()->status);
        $this->assertSame(9, $cashVariant->fresh()->stock);

        // 73 h: vence igual que los demás, con la misma liberación de stock.
        $this->travelTo('2026-10-10 11:00:00');
        $this->runCommand('1 pedidos cancelados');

        $cash->refresh();
        $this->assertSame(OrderStatus::Cancelled, $cash->status);
        $this->assertSame('system', $cash->cancelled_by->value);
        $this->assertSame('Venció el plazo de pago', $cash->cancel_reason);
        $this->assertSame(10, $cashVariant->fresh()->stock);

        // Correrlo otra vez no regresa el stock doble.
        $this->runCommand('0 pedidos cancelados');
        $this->assertSame(10, $cashVariant->fresh()->stock);
    }

    // ---- 2. Fecha de entrega y tope de 7 días -----------------------------------------------------

    public function test_the_delivery_date_moves_the_expiry_to_the_end_of_that_day()
    {
        $cash = $this->confirm($this->place('cash', $this->local()));
        $transfer = $this->confirm($this->place('transfer', $this->local()));

        $this->schedule($cash, '2026-10-10');
        $this->schedule($transfer, '2026-10-10');

        $this->assertSame('2026-10-10 23:59:59', $cash->fresh()->expires_at->toDateTimeString());
        // La transferencia no cambia: su fecha límite era solo para pagar.
        $this->assertSame('2026-10-09 10:00:00', $transfer->fresh()->expires_at->toDateTimeString());

        // Reprogramar mueve el vencimiento a la fecha nueva.
        $this->schedule($cash, '2026-10-08');
        $this->assertSame('2026-10-08 23:59:59', $cash->fresh()->expires_at->toDateTimeString());
    }

    public function test_the_expiry_never_goes_past_seven_days_from_the_creation()
    {
        $cash = $this->confirm($this->place('cash', $this->local()));
        $cap = '2026-10-14 10:00:00';

        $this->schedule($cash, '2026-10-27');
        $this->assertSame($cap, $cash->fresh()->expires_at->toDateTimeString());

        // El día 7 mismo: el final del día pasaría del tope, así que se queda en el tope.
        $this->schedule($cash, '2026-10-14');
        $this->assertSame($cap, $cash->fresh()->expires_at->toDateTimeString());

        // Un día antes del tope sí llega al final de ese día.
        $this->schedule($cash, '2026-10-13');
        $this->assertSame('2026-10-13 23:59:59', $cash->fresh()->expires_at->toDateTimeString());
    }

    public function test_giving_more_time_to_a_cash_order_stops_at_the_cap_and_the_button_is_back()
    {
        $cash = $this->place('cash');
        $service = app(OrderReservationService::class);

        // 72 h + 48 h = 120 h; + 48 h = 168 h, justo el tope de 7 días.
        $service->extend($cash, 48, $this->owner);
        $this->assertSame('2026-10-12 10:00:00', $cash->fresh()->expires_at->toDateTimeString());
        $service->extend($cash, 48, $this->owner);
        $this->assertSame('2026-10-14 10:00:00', $cash->fresh()->expires_at->toDateTimeString());

        try {
            $service->extend($cash, 24, $this->owner);
            $this->fail('Se esperaba que ya no se pudiera dar más tiempo.');
        } catch (InvalidOrderTransitionException $exception) {
            $this->assertStringContainsString('máximo de 7 días', $exception->getMessage());
        }
        $this->assertSame('2026-10-14 10:00:00', $cash->fresh()->expires_at->toDateTimeString());

        // Si lo que falta para el tope es menos que lo pedido, se queda en el tope.
        $other = $this->place('cash');
        $other->update(['expires_at' => '2026-10-13 22:00:00']);
        $service->extend($other, 24, $this->owner);
        $this->assertSame('2026-10-14 10:00:00', $other->fresh()->expires_at->toDateTimeString());

        // El botón «Dar más tiempo» se ve en pedidos en efectivo.
        $fresh = $this->place('cash');
        $this->panel($this->staff);
        Livewire::test(ViewOrder::class, ['record' => $fresh->getRouteKey()])
            ->assertActionVisible('extend')
            ->callAction('extend', data: ['hours' => 24])
            ->assertNotified();
        $this->assertSame('2026-10-11 10:00:00', $fresh->fresh()->expires_at->toDateTimeString());
    }

    public function test_the_extend_button_follows_the_cash_order_until_it_is_delivered()
    {
        $cash = $this->place('cash', $this->local());
        $transfer = $this->place('transfer');
        $statuses = app(OrderStatusService::class);
        $this->panel($this->owner);
        $detail = fn (Order $order) => Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()]);

        $detail($cash)->assertActionVisible('extend');
        $detail($transfer)->assertActionVisible('extend');

        // Confirmado y enviado: en efectivo se puede; en transferencia ya pagó, no hay nada que alargar.
        $this->confirm($cash);
        $this->confirm($transfer);
        $detail($cash)->assertActionVisible('extend');
        $detail($transfer)->assertActionHidden('extend');

        $statuses->transition($cash, OrderStatus::Shipped, $this->owner);
        $detail($cash)->assertActionVisible('extend');

        // Entregado: ya no hay nada que alargar.
        $statuses->transition($cash, OrderStatus::Delivered, $this->owner);
        $detail($cash)->assertActionHidden('extend');
    }

    // ---- 3. El efectivo confirmado no vence; si pasa su fecha se avisa ----------------------------

    public function test_a_confirmed_cash_order_with_a_valid_date_is_not_cancelled_and_nobody_is_warned()
    {
        $variant = $this->variant();
        $cash = $this->confirm($this->place('cash', $this->local(), $variant));
        $this->schedule($cash, '2026-10-12');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        // Pasan 5 días (mucho más de 72 h) pero su fecha de entrega sigue vigente.
        $this->travelTo('2026-10-12 12:00:00');
        $this->runCommand('0 pedidos cancelados');

        $this->assertSame(OrderStatus::Paid, $cash->fresh()->status);
        $this->assertSame(9, $variant->fresh()->stock);
        $this->assertSame(0, $this->overdueNotices($this->owner));
        $this->assertNull($cash->fresh()->cash_overdue_notified_at);
        $this->assertSame([], array_filter($this->telegram(), fn ($text) => str_contains($text, 'sin entregar')));
    }

    public function test_a_cash_order_past_its_date_is_reported_to_the_owner_once_and_not_cancelled()
    {
        $variant = $this->variant();
        $cash = $this->confirm($this->place('cash', $this->local(), $variant));
        $this->schedule($cash, '2026-10-10');
        // Una transferencia pagada con fecha vieja y un pedido ya entregado no avisan.
        $transfer = $this->confirm($this->place('transfer'));
        $delivered = $this->confirm($this->place('cash'));
        app(OrderStatusService::class)->transition($delivered, OrderStatus::Shipped, $this->owner);
        app(OrderStatusService::class)->transition($delivered, OrderStatus::Delivered, $this->owner);
        $delivered->update(['expires_at' => '2026-10-08 10:00:00']);

        $this->travelTo('2026-10-11 08:00:00');
        $this->runCommand('1 pedidos en efectivo sin entregar avisados al dueño');

        // Sigue pagado, con sus piezas apartadas.
        $this->assertSame(OrderStatus::Paid, $cash->fresh()->status);
        $this->assertSame(9, $variant->fresh()->stock);
        $this->assertNotNull($cash->fresh()->cash_overdue_notified_at);
        $this->assertNull($transfer->fresh()->cash_overdue_notified_at);
        $this->assertNull($delivered->fresh()->cash_overdue_notified_at);

        // Campana del panel: al owner y al personal de la tienda.
        foreach ([$this->owner, $this->staff] as $user) {
            $notice = $user->notifications()->get()->first(fn ($n) => $n->data['title'] === OverdueCashOrders::TITLE);
            $this->assertNotNull($notice);
            $this->assertStringContainsString('CP-00001', $notice->data['body']);
            $this->assertStringContainsString('Ana Pérez', $notice->data['body']);
            $this->assertStringContainsString('$580.00', $notice->data['body']);
            $this->assertStringContainsString("/admin/coppers/orders/{$cash->id}", $notice->data['actions'][0]['url']);
        }

        // Telegram.
        $this->assertContains(
            "Copper's · CP-00001 · Pedido en efectivo sin entregar\nCliente: Ana Pérez\nPor cobrar: \$580.00\nEntrega: Entrega local\nPasó su fecha: 10/10/2026 23:59",
            $this->telegram(),
        );

        // Una segunda corrida no repite el aviso.
        $before = count($this->telegram());
        $this->runCommand('0 pedidos cancelados');
        $this->assertSame(1, $this->overdueNotices($this->owner));
        $this->assertSame($before, count($this->telegram()));

        // Reprogramar o darle más tiempo lo vuelve a armar: si se vuelve a pasar, avisa otra vez.
        $this->schedule($cash, '2026-10-12');
        $this->assertNull($cash->fresh()->cash_overdue_notified_at);
        $this->travelTo('2026-10-13 08:00:00');
        $this->runCommand('1 pedidos en efectivo sin entregar avisados al dueño');
        $this->assertSame(2, $this->overdueNotices($this->owner));
        $this->assertSame(OrderStatus::Paid, $cash->fresh()->status);
    }

    public function test_the_dry_run_lists_the_overdue_cash_orders_without_touching_them()
    {
        $cash = $this->confirm($this->place('cash', $this->local()));
        $this->schedule($cash, '2026-10-08');
        $this->travelTo('2026-10-09 08:00:00');

        $this->artisan('orders:expire-unpaid --dry-run')
            ->expectsOutputToContain('CP-00001')
            ->expectsOutputToContain('Se avisaría de 1 pedidos en efectivo')
            ->assertSuccessful();

        $this->assertNull($cash->fresh()->cash_overdue_notified_at);
        $this->assertSame(0, $this->overdueNotices($this->owner));
    }

    public function test_an_unconfirmed_cash_order_past_its_date_is_still_cancelled_not_reported()
    {
        $variant = $this->variant();
        $cash = $this->place('cash', variant: $variant);
        $this->travelTo('2026-10-10 11:00:00');

        $this->runCommand('1 pedidos cancelados');

        $this->assertSame(OrderStatus::Cancelled, $cash->fresh()->status);
        $this->assertSame(10, $variant->fresh()->stock);
        $this->assertSame(0, $this->overdueNotices($this->owner));
    }

    // ---- 4. Conteo de ventas y «por cobrar» ------------------------------------------------------

    private function order(string $number, OrderStatus $status, float $total, PaymentMethod $payment, array $extra = []): Order
    {
        $order = Order::create([
            'store_id' => $this->coppers->id,
            'number' => $number,
            'channel' => OrderChannel::Web,
            'status' => $status,
            'delivery_method' => DeliveryMethod::Pickup,
            'subtotal' => $total,
            'total' => $total,
            'payment_method' => $payment,
            ...$extra,
        ]);

        return $order;
    }

    public function test_a_cash_order_counts_as_a_sale_only_when_delivered_and_before_that_it_is_receivable()
    {
        $this->travelTo('2026-10-15 14:00:00');
        $now = now()->toDateTimeString();
        $yesterday = now()->subDay()->toDateTimeString();

        // Efectivo sin entregar: por cobrar, no venta.
        $this->order('CP-1', OrderStatus::Paid, 1000, PaymentMethod::Cash, ['paid_at' => $now]);
        $this->order('CP-2', OrderStatus::Shipped, 500, PaymentMethod::Cash, ['paid_at' => $now]);
        $this->order('CP-3', OrderStatus::Reserved, 200, PaymentMethod::Cash, ['expires_at' => now()->addDay()]);
        // Efectivo entregado hoy (pagado desde hace días): venta de hoy, por su fecha de entrega.
        $this->order('CP-4', OrderStatus::Delivered, 300, PaymentMethod::Cash, ['paid_at' => now()->subDays(3)->toDateTimeString(), 'delivered_at' => $now]);
        // Transferencia pagada y venta de mostrador: cuentan desde que se pagan.
        $this->order('CP-5', OrderStatus::Paid, 400, PaymentMethod::Transfer, ['paid_at' => $now]);
        $this->order('CP-6', OrderStatus::Delivered, 250, PaymentMethod::Cash, ['channel' => OrderChannel::Counter, 'paid_at' => $now, 'delivered_at' => $now]);
        $this->order('CP-7', OrderStatus::Paid, 90, PaymentMethod::Transfer, ['paid_at' => $yesterday]);
        // Pedidos anteriores a la forma de pago (sin dato) siguen contando desde que se pagan.
        $legacy = Order::create([
            'store_id' => $this->coppers->id, 'number' => 'CP-8', 'channel' => OrderChannel::Web, 'status' => OrderStatus::Paid,
            'delivery_method' => DeliveryMethod::Pickup, 'subtotal' => 60, 'total' => 60, 'paid_at' => $now,
        ]);
        $this->assertNull($legacy->payment_method);

        $report = app(SalesReport::class);
        [$from, $to] = SalesPeriod::Today->range();

        // Hoy: 300 (efectivo entregado) + 400 + 250 + 60 = 1,010 en 4 pedidos. El efectivo sin entregar no suma.
        $summary = $report->summary($this->coppers, $from, $to);
        $this->assertSame([1010.0, 4], [$summary['sales'], $summary['orders']]);
        $this->assertSame(['2026-10-15' => 1010.0], $report->salesByDay($this->coppers, $from, $to));
        $this->assertSame(['web' => 760.0, 'mostrador' => 250.0], $report->salesByChannel($this->coppers, $from, $to));

        // Por cobrar: efectivo confirmado (1,000), enviado (500) y apartado vigente (200).
        $this->assertSame(['orders' => 3, 'amount' => 1700.0], $report->receivable($this->coppers));

        // La venta en efectivo entra el día que se entregó, no el que se pagó.
        $earlier = $report->summary($this->coppers, now()->subDays(3)->startOfDay(), now()->subDays(3)->endOfDay());
        $this->assertSame(0.0, $earlier['sales']);

        $this->panel($this->staff);
        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee('$1,010.00')
            ->assertSee('Por cobrar')
            ->assertSee('$1,700.00')
            ->assertSee('3 pedidos por cobrar');
    }

    public function test_a_cash_order_moves_from_receivable_to_sales_when_it_is_delivered()
    {
        $cash = $this->place('cash', $this->local());
        $statuses = app(OrderStatusService::class);
        $report = app(SalesReport::class);
        $today = SalesPeriod::Today->range();
        $state = fn () => [$report->summary($this->coppers, ...$today)['sales'], $report->receivable($this->coppers)['amount']];

        $this->assertSame([0.0, 580.0], $state());                  // apartado

        $this->confirm($cash);
        $this->assertSame([0.0, 580.0], $state());                  // confirmado

        $statuses->transition($cash, OrderStatus::Shipped, $this->owner);
        $this->assertSame([0.0, 580.0], $state());                  // enviado

        $statuses->transition($cash, OrderStatus::Delivered, $this->owner);
        $this->assertSame([580.0, 0.0], $state());                  // entregado y cobrado
    }

    public function test_a_cancelled_cash_order_never_adds_up()
    {
        $this->travelTo('2026-10-15 14:00:00');
        $report = app(SalesReport::class);
        $today = SalesPeriod::Today->range();

        // Confirmado en efectivo y cancelado antes de entregar.
        $paid = $this->confirm($this->place('cash'));
        app(OrderCancellationService::class)->cancel($paid, $this->owner, 'Cliente pidió cancelar');
        // Cancelado desde apartado.
        app(OrderCancellationService::class)->cancel($this->place('cash'), $this->owner, 'Cliente pidió cancelar');
        // Venta de mostrador en efectivo anulada.
        $counter = $this->order('CP-9', OrderStatus::Delivered, 777, PaymentMethod::Cash, ['channel' => OrderChannel::Counter, 'paid_at' => now()->toDateTimeString(), 'delivered_at' => now()->toDateTimeString()]);
        app(OrderCancellationService::class)->voidCounterSale($counter, $this->owner, 'Error de captura');
        // Venció solo.
        $expired = $this->place('cash');
        $this->travelTo('2026-10-19 10:00:00');
        $this->runCommand('1 pedidos cancelados');
        $this->assertSame(OrderStatus::Cancelled, $expired->fresh()->status);

        $this->travelTo('2026-10-15 14:00:00');
        $summary = $report->summary($this->coppers, ...$today);
        $this->assertSame([0.0, 0], [$summary['sales'], $summary['orders']]);
        $this->assertSame(['orders' => 0, 'amount' => 0.0], $report->receivable($this->coppers));
        $this->assertSame(['web' => 0.0, 'mostrador' => 0.0], $report->salesByChannel($this->coppers, ...$today));
        $this->assertSame([], $report->topProducts($this->coppers, ...$today));
    }

    public function test_the_overdue_notice_does_not_include_costs()
    {
        $cash = $this->confirm($this->place('cash'));
        $this->travelTo('2026-10-12 10:00:00');
        $cash->update(['expires_at' => '2026-10-11 10:00:00']);

        $this->runCommand('1 pedidos en efectivo sin entregar avisados al dueño');

        foreach ($this->telegram() as $text) {
            $this->assertStringNotContainsString('123:TOKEN', $text);
            $this->assertStringNotContainsStringIgnoringCase('cost', $text);
        }

        // Telegram recibió una petición por aviso, a la API con el token solo en la URL.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/sendMessage') && $request['chat_id'] === '99887766');
        $this->assertSame(Carbon::parse('2026-10-12 10:00:00')->toDateTimeString(), $cash->fresh()->cash_overdue_notified_at->toDateTimeString());
    }
}
