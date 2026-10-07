<?php

namespace Tests\Feature\Admin;

use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SalesPeriod;
use App\Enums\SizeType;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Filament\Widgets\ExpiringReservations;
use App\Filament\Widgets\InventoryStats;
use App\Filament\Widgets\OrdersNeedingAction;
use App\Filament\Widgets\RestockTable;
use App\Filament\Widgets\SalesByChannelChart;
use App\Filament\Widgets\SalesByDeliveryChart;
use App\Filament\Widgets\SalesStats;
use App\Filament\Widgets\TopProducts;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\CancellationRequestService;
use App\Services\OrderCancellationService;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Services\SalesReport;
use App\Services\StockService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class SalesDashboardRulesTest extends TestCase
{
    use RefreshDatabase;

    private Store $coppers;

    private Store $g5;

    private User $owner;

    private User $staffG5;

    private User $staffCoppers;

    private Customer $ana;

    protected function setUp(): void
    {
        parent::setUp();

        // Jueves 15 de octubre de 2026, 2:00 p. m.
        $this->travelTo(Carbon::parse('2026-10-15 14:00:00'));

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost']);

        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->staffG5 = User::factory()->create(['role' => UserRole::Staff]);
        $this->staffG5->stores()->attach($this->g5->id);
        $this->staffCoppers = User::factory()->create(['role' => UserRole::Staff]);
        $this->staffCoppers->stores()->attach($this->coppers->id);
        $this->ana = Customer::factory()->create(['name' => 'Ana Pérez']);
    }

    /** @param array<string, int> $sizes talla => stock */
    private function product(Store $store, string $name, array $sizes = ['M' => 50], float $price = 500, float $cost = 137.77): Product
    {
        $category = Category::firstOrCreate(
            ['store_id' => $store->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug(),
            'price' => $price,
            'cost' => $cost,
        ]);

        foreach ($sizes as $size => $stock) {
            $product->variants()->create(['size' => (string) $size, 'stock' => $stock]);
        }

        return $product;
    }

    private function variant(Product $product): ProductVariant
    {
        return $product->variants()->orderBy('id')->firstOrFail();
    }

    /** @param list<array{ProductVariant, int, float}> $items variante, piezas, precio unitario */
    private function order(string $number, OrderStatus $status, float $total, ?string $paidAt = null, ?Store $store = null, array $extra = [], array $items = []): Order
    {
        $order = Order::create([
            'store_id' => ($store ?? $this->g5)->id,
            'customer_id' => $this->ana->id,
            'number' => $number,
            'channel' => OrderChannel::Web,
            'status' => $status,
            'delivery_method' => DeliveryMethod::Pickup,
            'subtotal' => $total,
            'total' => $total,
            'paid_at' => $paidAt,
            ...$extra,
        ]);

        foreach ($items as [$variant, $pieces, $unitPrice]) {
            $order->items()->create([
                'product_variant_id' => $variant->id,
                'product_name' => $variant->product()->withoutGlobalScopes()->first()->name,
                'size' => $variant->size,
                'unit_price' => $unitPrice,
                'quantity' => $pieces,
            ]);
        }

        return $order;
    }

    private function panelAs(User $user, Store $store): void
    {
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($store);
    }

    private function chart(string $widget, string $period = 'today'): array
    {
        return (new ReflectionMethod($widget, 'getData'))->invoke(
            Livewire::test($widget, ['pageFilters' => ['period' => $period]])->instance(),
        );
    }

    // ---- Cancelados y anulados no suman -----------------------------------------------------------

    public function test_cancelled_orders_and_voided_counter_sales_count_for_nothing()
    {
        $shirt = $this->variant($this->product($this->g5, 'Playera', price: 400, cost: 100));
        $report = app(SalesReport::class);
        [$from, $to] = SalesPeriod::Today->range();

        // Una venta de mostrador que se queda, otra que se anula, y un pedido web pagado que se cancela.
        $kept = app(OrderService::class)->placeCounterSale($this->g5, $this->owner, [['variant_id' => $shirt->id, 'quantity' => 1]], PaymentMethod::Cash);
        $voided = app(OrderService::class)->placeCounterSale($this->g5, $this->owner, [['variant_id' => $shirt->id, 'quantity' => 3]], PaymentMethod::Card);
        $web = $this->order('G5-90001', OrderStatus::Paid, 800, now()->toDateTimeString(), items: [[$shirt, 2, 400]]);
        $reserved = $this->order('G5-90002', OrderStatus::Reserved, 400, extra: ['expires_at' => now()->addDay()], items: [[$shirt, 1, 400]]);

        $this->assertSame(2400.0, $report->summary($this->g5, $from, $to)['sales']);   // 400 + 1,200 + 800

        app(OrderCancellationService::class)->voidCounterSale($voided, $this->owner, 'Error de captura');
        app(OrderCancellationService::class)->cancel($web, $this->owner, 'Cliente pidió cancelar');

        // Solo queda la venta de mostrador de 400. El apartado no es venta: va en «por cobrar».
        $this->assertSame(
            ['sales' => 400.0, 'orders' => 1, 'average' => 400.0, 'cost' => 100.0, 'profit' => 300.0],
            $report->summary($this->g5, $from, $to),
        );
        $this->assertSame(['2026-10-15' => 400.0], $report->salesByDay($this->g5, $from, $to));
        $this->assertSame(['web' => 0.0, 'mostrador' => 400.0], $report->salesByChannel($this->g5, $from, $to));
        $this->assertSame(['recoger' => 400.0, 'local' => 0.0, 'nacional' => 0.0], $report->salesByDelivery($this->g5, $from, $to));
        $this->assertSame([['name' => 'Playera', 'pieces' => 1, 'revenue' => 400.0]], $report->topProducts($this->g5, $from, $to));
        $this->assertSame(['orders' => 1, 'amount' => 400.0], $report->receivable($this->g5));

        // Cancelar el apartado lo saca también de «por cobrar».
        app(OrderCancellationService::class)->cancel($reserved, $this->owner, 'Cliente no pagó', restock: false);
        $this->assertSame(['orders' => 0, 'amount' => 0.0], $report->receivable($this->g5));

        $this->panelAs($this->owner, $this->g5);
        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee('$400.00')
            ->assertDontSee('$2,400.00')
            ->assertDontSee('$1,200.00')
            ->assertDontSee('$800.00');

        $this->assertSame([1], $this->chart(TopProducts::class)['datasets'][0]['data']);
        $this->assertSame($kept->id, Order::where('status', OrderStatus::Delivered->value)->sole()->id);
    }

    // ---- Cada tienda ve solo lo suyo --------------------------------------------------------------

    public function test_each_store_only_sees_its_own_numbers()
    {
        $ball = $this->variant($this->product($this->g5, 'Balón', price: 300, cost: 90));
        $dress = $this->variant($this->product($this->coppers, 'Vestido', price: 2000, cost: 700));
        $now = now()->toDateTimeString();

        $this->order('G5-00001', OrderStatus::Paid, 600, $now, items: [[$ball, 2, 300]], extra: ['delivery_method' => DeliveryMethod::Local]);
        $this->order('G5-00002', OrderStatus::Reserved, 300, extra: ['expires_at' => now()->addHours(5)]);
        $this->order('CP-00001', OrderStatus::Delivered, 4000, $now, $this->coppers, ['delivery_method' => DeliveryMethod::National], [[$dress, 2, 2000]]);
        $this->order('CP-00002', OrderStatus::Reserved, 2000, store: $this->coppers, extra: ['expires_at' => now()->addHours(5)]);

        $this->panelAs($this->staffG5, $this->g5);

        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee('$600.00')
            ->assertSee('$300.00')      // por cobrar
            ->assertDontSee('$4,000.00')
            ->assertDontSee('$2,000.00')
            ->assertDontSee('$4,600.00');

        $this->assertSame([600.0, 0.0], $this->chart(SalesByChannelChart::class)['datasets'][0]['data']);
        $this->assertSame(['Recoger en tienda', 'Entrega local', 'Envío nacional'], $this->chart(SalesByDeliveryChart::class)['labels']);
        $this->assertSame([0.0, 600.0, 0.0], $this->chart(SalesByDeliveryChart::class)['datasets'][0]['data']);
        $this->assertSame(['Balón'], $this->chart(TopProducts::class)['labels']);

        Livewire::test(OrdersNeedingAction::class)->assertSee('G5-00001')->assertDontSee('CP-00001');
        Livewire::test(ExpiringReservations::class)->assertSee('G5-00002')->assertDontSee('CP-00002');
        Livewire::test(RestockTable::class)->assertDontSee('Vestido');

        $this->panelAs($this->staffCoppers, $this->coppers);

        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee('$4,000.00')
            ->assertSee('$2,000.00')
            ->assertDontSee('$600.00');

        $this->assertSame([0.0, 0.0, 4000.0], $this->chart(SalesByDeliveryChart::class)['datasets'][0]['data']);
        $this->assertSame(['Vestido'], $this->chart(TopProducts::class)['labels']);

        $this->assertSame('doughnut', (new ReflectionMethod(SalesByDeliveryChart::class, 'getType'))->invoke(Livewire::test(SalesByDeliveryChart::class)->instance()));
    }

    // ---- El empleado no ve utilidad ni costos -----------------------------------------------------

    public function test_staff_sees_no_profit_and_no_costs_anywhere_on_the_dashboard()
    {
        // Costo 137.77 por pieza: 3 vendidas = 413.31 de costo, 1,086.69 de utilidad.
        $shoe = $this->variant($this->product($this->g5, 'Tenis', ['27' => 10], price: 500, cost: 137.77));
        $this->order('G5-00001', OrderStatus::Paid, 1500, now()->toDateTimeString(), items: [[$shoe, 3, 500]]);

        $this->panelAs($this->owner, $this->g5);
        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee('Utilidad estimada')
            ->assertSee('$1,086.69');
        Livewire::test(InventoryStats::class)->assertSee('$1,377.70');   // inventario a costo

        $this->panelAs($this->staffG5, $this->g5);
        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee('$1,500.00')
            ->assertDontSee('Utilidad')
            ->assertDontSee('$1,086.69')
            ->assertDontSee('413.31');

        $forbidden = ['Utilidad', 'a costo', '137.77', '413.31', '1,086.69', '1,377.70'];

        foreach ([SalesStats::class, InventoryStats::class, TopProducts::class, RestockTable::class, OrdersNeedingAction::class] as $widget) {
            $html = Livewire::test($widget, ['pageFilters' => ['period' => 'today']])->html(false);

            foreach ($forbidden as $text) {
                $this->assertStringNotContainsString($text, $html, "{$widget} muestra «{$text}» al empleado");
            }
        }

        $page = $this->get('/admin/g5')->assertOk()->getContent();
        foreach ($forbidden as $text) {
            $this->assertStringNotContainsString($text, $page);
        }
    }

    // ---- Periodo anterior -------------------------------------------------------------------------

    public function test_the_previous_period_is_the_right_window()
    {
        $range = fn (SalesPeriod $period) => array_map(fn ($date) => $date->toDateTimeString(), $period->previousRange());

        $this->assertSame(['2026-10-14 00:00:00', '2026-10-14 23:59:59'], $range(SalesPeriod::Today));
        $this->assertSame(['2026-10-02 00:00:00', '2026-10-08 23:59:59'], $range(SalesPeriod::Week));
        $this->assertSame(['2026-08-17 00:00:00', '2026-09-15 23:59:59'], $range(SalesPeriod::Month30));
        $this->assertSame(['2026-09-01 00:00:00', '2026-09-15 23:59:59'], $range(SalesPeriod::ThisMonth));

        // Pegado al periodo actual, sin hueco ni traslape.
        $this->assertSame('2026-10-09 00:00:00', SalesPeriod::Week->range()[0]->toDateTimeString());
        $this->assertSame('2026-09-16 00:00:00', SalesPeriod::Month30->range()[0]->toDateTimeString());

        // Fin de mes: el 31 de marzo se compara con todo febrero, no se pasa a marzo.
        $this->travelTo(Carbon::parse('2026-03-31 09:00:00'));
        $this->assertSame(['2026-02-01 00:00:00', '2026-02-28 23:59:59'], $range(SalesPeriod::ThisMonth));

        // Por defecto, 30 días.
        $this->assertSame(SalesPeriod::Month30, SalesPeriod::fromFilters(null));
        $this->assertSame(SalesPeriod::Month30, SalesPeriod::fromFilters(['period' => 'cualquiera']));
        $this->assertSame(SalesPeriod::Week, SalesPeriod::fromFilters(['period' => 'week']));
    }

    public function test_the_cards_compare_with_the_previous_period()
    {
        $this->assertSame(50.0, SalesReport::change(1500, 1000));
        $this->assertSame(-25.0, SalesReport::change(750, 1000));
        $this->assertSame(0.0, SalesReport::change(1000, 1000));
        $this->assertSame(33.3, SalesReport::change(4, 3));
        $this->assertNull(SalesReport::change(500, 0));
        $this->assertNull(SalesReport::change(0, 0));

        // Hoy: 1,500 en 3 pedidos (ticket 500). Ayer: 1,000 en 1 pedido (ticket 1,000).
        foreach ([['A', 500, '2026-10-15 09:00:00'], ['B', 500, '2026-10-15 10:00:00'], ['C', 500, '2026-10-15 11:00:00'], ['D', 1000, '2026-10-14 18:00:00']] as [$number, $total, $paidAt]) {
            $this->order("G5-{$number}", OrderStatus::Paid, $total, $paidAt);
        }
        // Antier no entra en la comparación de «hoy»; un cancelado de ayer tampoco.
        $this->order('G5-E', OrderStatus::Delivered, 9000, '2026-10-13 12:00:00');
        $this->order('G5-F', OrderStatus::Cancelled, 7000, '2026-10-14 12:00:00');
        // Otra tienda, ayer.
        $this->order('CP-A', OrderStatus::Paid, 123, '2026-10-14 12:00:00', $this->coppers);

        $this->panelAs($this->staffG5, $this->g5);

        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee('$1,500.00')
            ->assertSee('50% más que el periodo anterior')      // ventas: 1,500 contra 1,000
            ->assertSee('200% más que el periodo anterior')     // pedidos: 3 contra 1
            ->assertSee('50% menos que el periodo anterior');   // ticket: 500 contra 1,000

        // 7 días: 11,500 (A–E) contra 0 en los 7 anteriores.
        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'week']])
            ->assertSee('$11,500.00')
            ->assertSee('El periodo anterior fue 0')
            ->assertDontSee('más que el periodo anterior');

        // Sin ventas ahora ni antes.
        $this->panelAs($this->staffCoppers, $this->coppers);
        $this->travelTo(Carbon::parse('2026-12-20 10:00:00'));
        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee('Sin movimiento, igual que el periodo anterior');
    }

    // ---- Por cobrar -------------------------------------------------------------------------------

    public function test_receivable_counts_only_reservations_still_open()
    {
        $this->order('G5-00001', OrderStatus::Reserved, 700, extra: ['expires_at' => now()->addHours(30)]);
        $this->order('G5-00002', OrderStatus::Reserved, 250.50, extra: ['expires_at' => now()->addMinutes(5)]);
        $this->order('G5-00003', OrderStatus::Reserved, 999, extra: ['expires_at' => now()->subMinute()]);   // ya venció
        $this->order('G5-00004', OrderStatus::Paid, 5000, now()->toDateTimeString());
        $this->order('G5-00005', OrderStatus::Cancelled, 3000);

        $this->assertSame(['orders' => 2, 'amount' => 950.5], app(SalesReport::class)->receivable($this->g5));

        $this->panelAs($this->staffG5, $this->g5);
        Livewire::test(SalesStats::class)
            ->assertSee('Por cobrar')
            ->assertSee('$950.50')
            ->assertSee('2 pedidos por cobrar');
    }

    // ---- Apartados por vencer, en orden -----------------------------------------------------------

    public function test_expiring_reservations_come_in_order_with_a_button_to_open_the_order()
    {
        $inEleven = $this->order('G5-00001', OrderStatus::Reserved, 100, extra: ['expires_at' => now()->addHours(11)]);
        $inOne = $this->order('G5-00002', OrderStatus::Reserved, 100, extra: ['expires_at' => now()->addHour()]);
        $overdue = $this->order('G5-00003', OrderStatus::Reserved, 100, extra: ['expires_at' => now()->subHours(2)]);
        $inFive = $this->order('G5-00004', OrderStatus::Reserved, 100, extra: ['expires_at' => now()->addHours(5)]);
        $inThirteen = $this->order('G5-00005', OrderStatus::Reserved, 100, extra: ['expires_at' => now()->addHours(13)]);

        $this->panelAs($this->staffG5, $this->g5);

        Livewire::test(ExpiringReservations::class)
            ->assertCanSeeTableRecords([$overdue, $inOne, $inFive, $inEleven], inOrder: true)
            ->assertCanNotSeeTableRecords([$inThirteen])
            ->assertSee('Tiempo restante')
            ->assertSee('Abrir pedido')
            ->assertSee("/admin/g5/orders/{$inOne->id}", escape: false);
    }

    // ---- Pedidos que requieren acción -------------------------------------------------------------

    public function test_orders_needing_action_lists_requests_unscheduled_deliveries_and_paid_not_shipped()
    {
        $yesterday = now()->subDay()->toDateTimeString();
        $today = now()->toDateTimeString();

        $paid = $this->order('G5-00001', OrderStatus::Paid, 100, $yesterday);
        $requested = $this->order('G5-00002', OrderStatus::Paid, 100, $today);
        app(CancellationRequestService::class)->request($requested, $this->ana, 'Ya no lo quiero');
        $localPaid = $this->order('G5-00003', OrderStatus::Paid, 100, $today, extra: ['delivery_method' => DeliveryMethod::Local]);
        $localShipped = $this->order('G5-00004', OrderStatus::Shipped, 100, $today, extra: ['delivery_method' => DeliveryMethod::Local]);

        // No requieren acción.
        $localScheduled = $this->order('G5-00005', OrderStatus::Shipped, 100, $today, extra: ['delivery_method' => DeliveryMethod::Local, 'delivery_scheduled_date' => today()]);
        $pickupShipped = $this->order('G5-00006', OrderStatus::Shipped, 100, $today);
        $reserved = $this->order('G5-00007', OrderStatus::Reserved, 100);
        $delivered = $this->order('G5-00008', OrderStatus::Delivered, 100, $today, extra: ['delivery_method' => DeliveryMethod::Local]);
        $cancelled = $this->order('G5-00009', OrderStatus::Cancelled, 100, $today, extra: ['cancellation_requested_at' => now()]);
        $other = $this->order('CP-00001', OrderStatus::Paid, 100, $today, $this->coppers);

        $this->panelAs($this->staffG5, $this->g5);

        Livewire::test(OrdersNeedingAction::class)
            ->assertSee('Pedidos que requieren acción')
            // La solicitud de cancelación va primero; luego por antigüedad del pago.
            ->assertCanSeeTableRecords([$requested, $paid, $localPaid, $localShipped], inOrder: true)
            ->assertCanNotSeeTableRecords([$localScheduled, $pickupShipped, $reserved, $delivered, $cancelled, $other])
            ->assertTableColumnStateSet('reasons', ['Solicitó cancelar', 'Pagado sin enviar'], $requested)
            ->assertTableColumnStateSet('reasons', ['Pagado sin enviar'], $paid)
            ->assertTableColumnStateSet('reasons', ['Entrega sin fecha', 'Pagado sin enviar'], $localPaid)
            ->assertTableColumnStateSet('reasons', ['Entrega sin fecha'], $localShipped)
            ->assertSee("/admin/g5/orders/{$requested->id}", escape: false);
    }

    // ---- Alertas de stock -------------------------------------------------------------------------

    public function test_stock_alerts_list_sold_out_models_and_sizes_with_one_piece()
    {
        $soldOut = $this->product($this->g5, 'Agotado', ['26' => 0, '27' => 0]);
        $lastPiece = $this->product($this->g5, 'Con última pieza', ['26' => 1, '27' => 8, '28' => 1]);
        $oneSizeGone = $this->product($this->g5, 'Una talla en cero', ['26' => 0, '27' => 6]);
        $fine = $this->product($this->g5, 'Con dos', ['26' => 2]);
        $off = $this->product($this->g5, 'Apagado', ['26' => 0]);
        $off->update(['is_active' => false]);
        $other = $this->product($this->coppers, 'Ajeno', ['M' => 0]);

        $this->panelAs($this->staffG5, $this->g5);

        Livewire::test(RestockTable::class)
            ->assertSee('Alertas de stock')
            ->assertCanSeeTableRecords([$soldOut, $lastPiece], inOrder: true)
            ->assertCanNotSeeTableRecords([$oneSizeGone, $fine, $off, $other])
            ->assertTableColumnStateSet('alert', 'Agotado', $soldOut)
            ->assertTableColumnStateSet('alert', 'Última pieza', $lastPiece)
            ->assertTableColumnStateSet('last_piece_sizes', ['26', '28'], $lastPiece)
            ->assertSee("/admin/g5/products/{$soldOut->id}/edit", escape: false);
    }

    // ---- Más vendidos -----------------------------------------------------------------------------

    public function test_top_products_is_a_bar_chart_with_the_five_best_sellers_by_pieces()
    {
        foreach ([1 => 3, 2 => 9, 3 => 1, 4 => 7, 5 => 5, 6 => 2, 7 => 8] as $position => $pieces) {
            $variant = $this->variant($this->product($this->g5, "Producto {$position}", price: 10));
            $this->order("G5-0000{$position}", OrderStatus::Paid, 10 * $pieces, now()->toDateTimeString(), items: [[$variant, $pieces, 10]]);
        }

        $this->panelAs($this->staffG5, $this->g5);
        $data = $this->chart(TopProducts::class);

        $this->assertSame(['Producto 2', 'Producto 7', 'Producto 4', 'Producto 5', 'Producto 1'], $data['labels']);
        $this->assertSame([9, 8, 7, 5, 3], $data['datasets'][0]['data']);
        $this->assertSame('bar', (new ReflectionMethod(TopProducts::class, 'getType'))->invoke(Livewire::test(TopProducts::class)->instance()));
    }

    // ---- Campana ----------------------------------------------------------------------------------

    public function test_the_bell_gets_one_notice_per_event_without_duplicates()
    {
        $shoe = $this->variant($this->product($this->g5, 'Tenis', ['27' => 2]));
        $titles = fn (User $user) => $user->notifications()->get()->map(fn ($notification) => $notification->data['title'])->sort()->values()->all();

        // Pedido nuevo por la tienda en línea.
        $this->actingAs($this->ana, 'customer');
        $this->post('http://g5.localhost/carrito', ['variant_id' => $shoe->id, 'quantity' => 1])->assertSessionHasNoErrors();
        $this->post('http://g5.localhost/checkout', ['delivery_method' => 'recoger', 'phone' => '755 123 4567'])->assertSessionHasNoErrors();
        $order = Order::withoutGlobalScopes()->sole();

        $this->assertSame(['Pedido nuevo'], $titles($this->staffG5));

        // Solicitud de cancelación (y un segundo intento que se rechaza).
        app(OrderStatusService::class)->transition($order, OrderStatus::Paid, $this->owner);
        app(CancellationRequestService::class)->request($order, $this->ana, 'Ya no lo quiero');
        try {
            app(CancellationRequestService::class)->request($order, $this->ana, 'Otra vez');
        } catch (\Throwable) {
        }

        $this->assertSame(['Pedido nuevo', 'Solicitud de cancelación'], $titles($this->staffG5));

        // Modelo agotado: se va la última pieza.
        app(StockService::class)->change($shoe, StockMovementType::Adjustment, -1, $this->owner);

        $expected = ['Pedido nuevo', 'Producto agotado', 'Solicitud de cancelación'];
        $this->assertSame($expected, $titles($this->staffG5));
        $this->assertSame($expected, $titles($this->owner));
        $this->assertSame([], $titles($this->staffCoppers));
    }

    public function test_the_dashboard_renders_the_new_widgets_and_defaults_to_thirty_days()
    {
        foreach ([$this->owner, $this->staffG5] as $user) {
            $this->actingAs($user, 'web')
                ->get('/admin/g5')
                ->assertOk()
                ->assertSeeLivewire(SalesByDeliveryChart::class)
                ->assertSeeLivewire(OrdersNeedingAction::class)
                ->assertSeeLivewire(RestockTable::class);
        }

        $this->panelAs($this->staffG5, $this->g5);
        Livewire::test(SalesStats::class)->assertSee('Ventas · 30 días');
    }
}
