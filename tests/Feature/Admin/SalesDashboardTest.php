<?php

namespace Tests\Feature\Admin;

use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\SalesPeriod;
use App\Enums\SizeType;
use App\Enums\UserRole;
use App\Filament\Widgets\ExpiringReservations;
use App\Filament\Widgets\LatestOrders;
use App\Filament\Widgets\OrdersByStatusChart;
use App\Filament\Widgets\SalesByChannelChart;
use App\Filament\Widgets\SalesByDayChart;
use App\Filament\Widgets\SalesStats;
use App\Filament\Widgets\TopProducts;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\SalesReport;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class SalesDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Store $coopers;

    private Store $g5;

    private User $owner;

    private User $staffG5;

    private User $staffCoopers;

    private ProductVariant $expensive;

    private ProductVariant $cheap;

    protected function setUp(): void
    {
        parent::setUp();

        // Jueves 15 de octubre de 2026, 2:00 p. m.
        $this->travelTo(Carbon::parse('2026-10-15 14:00:00'));

        $this->coopers = Store::create(['name' => 'Coopers', 'slug' => 'coopers', 'domain' => 'coopers.localhost']);
        $this->g5 = Store::create(['name' => 'G5 Esports', 'slug' => 'g5', 'domain' => 'g5.localhost']);

        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->staffG5 = User::factory()->create(['role' => UserRole::Staff]);
        $this->staffG5->stores()->attach($this->g5->id);
        $this->staffCoopers = User::factory()->create(['role' => UserRole::Staff]);
        $this->staffCoopers->stores()->attach($this->coopers->id);

        $this->expensive = $this->variant($this->g5, 'Tenis P1', price: 500, cost: 100);
        $this->cheap = $this->variant($this->g5, 'Playera P2', price: 250, cost: 50);
    }

    private function variant(Store $store, string $name, float $price, float $cost): ProductVariant
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

        return $product->variants()->create(['size' => 'M', 'stock' => 50]);
    }

    /**
     * @param  list<array{ProductVariant, int, float}>  $items  variante, piezas, precio unitario
     */
    private function order(
        string $number,
        OrderStatus $status,
        float $total,
        ?string $paidAt = null,
        ?Store $store = null,
        OrderChannel $channel = OrderChannel::Web,
        array $items = [],
        ?string $createdAt = null,
        ?string $expiresAt = null,
    ): Order {
        $order = Order::create([
            'store_id' => ($store ?? $this->g5)->id,
            'number' => $number,
            'channel' => $channel,
            'status' => $status,
            'delivery_method' => DeliveryMethod::Pickup,
            'subtotal' => $total,
            'total' => $total,
            'paid_at' => $paidAt,
            'expires_at' => $expiresAt,
        ]);

        $order->forceFill(['created_at' => $createdAt ?? $paidAt ?? now()])->save();

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

    /** Escenario con ventas, un cancelado, un apartado y datos fuera del periodo / de otra tienda. */
    private function seedSales(): void
    {
        $this->order('A', OrderStatus::Paid, 1000, '2026-10-15 10:00:00', items: [[$this->expensive, 2, 500]]);
        $this->order('B', OrderStatus::Delivered, 500, '2026-10-12 12:00:00', items: [[$this->expensive, 1, 500]]);
        $this->order('C', OrderStatus::Shipped, 200, '2026-10-15 09:00:00', channel: OrderChannel::Counter, items: [[$this->cheap, 1, 200]]);
        $this->order('D', OrderStatus::Cancelled, 9999, '2026-10-15 11:00:00', items: [[$this->expensive, 5, 500]]);
        $this->order('E', OrderStatus::Reserved, 700, null, expiresAt: '2026-10-16 10:00:00');
        $this->order('F', OrderStatus::Paid, 300, '2026-09-05 10:00:00', items: [[$this->cheap, 1, 300]]);

        $other = $this->variant($this->coopers, 'Ajeno', price: 100, cost: 10);
        $this->order('G', OrderStatus::Paid, 5000, '2026-10-15 08:00:00', store: $this->coopers, items: [[$other, 3, 100]]);
    }

    private function panelAs(User $user, Store $store): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($store);
    }

    public function test_stat_cards_have_the_right_totals_for_each_period()
    {
        $this->seedSales();
        $this->panelAs($this->owner, $this->g5);

        // Hoy: A (1,000) + C (200). Costo: 2 × 100 + 1 × 50 = 250.
        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee('$1,200.00')   // ventas
            ->assertSee('$600.00')     // ticket promedio (1,200 / 2)
            ->assertSee('$950.00')     // ganancia estimada
            ->assertSee('Apartados pendientes de pago');

        // 7 días: A + B + C. Costo: 200 + 100 + 50 = 350.
        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'week']])
            ->assertSee('$1,700.00')
            ->assertSee('$566.67')
            ->assertSee('$1,350.00');

        // 30 días y este mes: lo mismo (F es de hace 40 días y G es de otra tienda).
        foreach (['days30', 'month'] as $period) {
            Livewire::test(SalesStats::class, ['pageFilters' => ['period' => $period]])
                ->assertSee('$1,700.00')
                ->assertSee('$1,350.00');
        }

        $summary = app(SalesReport::class)->summary($this->g5, ...SalesPeriod::Today->range());
        $this->assertSame(['sales' => 1200.0, 'orders' => 2, 'average' => 600.0, 'cost' => 250.0, 'profit' => 950.0], $summary);
        $this->assertSame(1, app(SalesReport::class)->pendingReservations($this->g5));
    }

    public function test_the_card_numbers_are_exact_not_just_visible()
    {
        $this->seedSales();
        $report = app(SalesReport::class);

        $week = $report->summary($this->g5, ...SalesPeriod::Week->range());
        $this->assertSame(1700.0, $week['sales']);
        $this->assertSame(3, $week['orders']);
        $this->assertSame(566.67, $week['average']);
        $this->assertSame(350.0, $week['cost']);
        $this->assertSame(1350.0, $week['profit']);

        // Otra tienda: solo sus propias ventas.
        $coopers = $report->summary($this->coopers, ...SalesPeriod::Week->range());
        $this->assertSame(5000.0, $coopers['sales']);
        $this->assertSame(1, $coopers['orders']);
    }

    public function test_a_cancelled_order_does_not_count_as_a_sale()
    {
        $order = $this->order('A', OrderStatus::Paid, 1000, '2026-10-15 10:00:00', items: [[$this->expensive, 2, 500]]);
        $this->order('B', OrderStatus::Paid, 400, '2026-10-15 11:00:00', channel: OrderChannel::Counter, items: [[$this->cheap, 1, 400]]);
        $report = app(SalesReport::class);
        [$from, $to] = SalesPeriod::Today->range();

        $this->assertSame(1400.0, $report->summary($this->g5, $from, $to)['sales']);
        $this->assertSame(2, $report->summary($this->g5, $from, $to)['orders']);

        $order->update(['status' => OrderStatus::Cancelled]);

        $summary = $report->summary($this->g5, $from, $to);
        $this->assertSame(400.0, $summary['sales']);
        $this->assertSame(1, $summary['orders']);
        $this->assertSame(400.0, $summary['average']);
        $this->assertSame(350.0, $summary['profit']);   // 400 - 50 (el costo del cancelado tampoco cuenta)

        $this->assertSame(400.0, $report->salesByDay($this->g5, $from, $to)['2026-10-15']);
        $this->assertSame(['web' => 0.0, 'mostrador' => 400.0], $report->salesByChannel($this->g5, $from, $to));
        $this->assertSame(['Playera P2'], array_column($report->topProducts($this->g5, $from, $to), 'name'));

        // En la dona sí aparece, como cancelado.
        $this->assertSame(1, $report->ordersByStatus($this->g5, $from, $to)['cancelado']);

        $this->panelAs($this->owner, $this->g5);
        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'today']])
            ->assertSee('$400.00')
            ->assertDontSee('$1,400.00')
            ->assertDontSee('$1,000.00');
    }

    public function test_staff_does_not_see_the_estimated_profit()
    {
        $this->seedSales();

        $this->panelAs($this->staffG5, $this->g5);
        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'week']])
            ->assertSee('Ventas')
            ->assertSee('$1,700.00')
            ->assertSee('Ticket promedio')
            ->assertDontSee('Ganancia estimada')
            ->assertDontSee('$1,350.00');

        $this->panelAs($this->owner, $this->g5);
        Livewire::test(SalesStats::class, ['pageFilters' => ['period' => 'week']])
            ->assertSee('Ganancia estimada');
    }

    public function test_charts_use_the_period_and_the_store()
    {
        $this->seedSales();
        $this->panelAs($this->staffG5, $this->g5);

        $data = fn (string $widget, string $period) => (new ReflectionMethod($widget, 'getData'))->invoke(
            Livewire::test($widget, ['pageFilters' => ['period' => $period]])->instance(),
        );

        // Línea: 7 días con los días sin ventas en 0.
        $line = $data(SalesByDayChart::class, 'week');
        $this->assertSame(['09/10', '10/10', '11/10', '12/10', '13/10', '14/10', '15/10'], $line['labels']);
        $this->assertSame([0.0, 0.0, 0.0, 500.0, 0.0, 0.0, 1200.0], $line['datasets'][0]['data']);

        // Dona: pedidos creados en el periodo por estado.
        $donut = $data(OrdersByStatusChart::class, 'week');
        $this->assertSame(['Apartado', 'Pagado', 'Enviado', 'Entregado', 'Cancelado'], $donut['labels']);
        $this->assertSame([1, 1, 1, 1, 1], $donut['datasets'][0]['data']);

        // Barras: web contra mostrador.
        $bars = $data(SalesByChannelChart::class, 'week');
        $this->assertSame(['Web', 'Mostrador'], $bars['labels']);
        $this->assertSame([1500.0, 200.0], $bars['datasets'][0]['data']);

        $this->assertSame('line', (new ReflectionMethod(SalesByDayChart::class, 'getType'))->invoke(Livewire::test(SalesByDayChart::class)->instance()));
        $this->assertSame('doughnut', (new ReflectionMethod(OrdersByStatusChart::class, 'getType'))->invoke(Livewire::test(OrdersByStatusChart::class)->instance()));
        $this->assertSame('bar', (new ReflectionMethod(SalesByChannelChart::class, 'getType'))->invoke(Livewire::test(SalesByChannelChart::class)->instance()));
    }

    public function test_latest_orders_shows_the_ten_most_recent_with_a_link()
    {
        $customer = Customer::create(['name' => 'Ana López', 'email' => 'ana@example.com']);
        $orders = [];
        for ($i = 1; $i <= 12; $i++) {
            $orders[$i] = $this->order(sprintf('P-%02d', $i), OrderStatus::Paid, 100 + $i, createdAt: now()->subHours(13 - $i)->toDateTimeString(), channel: OrderChannel::Counter);
        }
        $orders[12]->update(['customer_id' => $customer->id]);
        $theirs = $this->order('X-01', OrderStatus::Paid, 50, store: $this->coopers, channel: OrderChannel::Counter);

        $this->panelAs($this->staffG5, $this->g5);

        $component = Livewire::test(LatestOrders::class);
        $this->assertCount(10, $component->instance()->getTableRecords());

        $component
            ->assertCanSeeTableRecords(array_slice($orders, 2, 10, preserve_keys: false))
            ->assertCanNotSeeTableRecords([$orders[1], $orders[2], $theirs])
            ->assertSee('Últimos pedidos')
            ->assertSee('Ana López')
            ->assertSee("/admin/g5/orders/{$orders[12]->id}", escape: false);
    }

    public function test_top_products_ranks_by_pieces_sold_in_the_period()
    {
        $this->seedSales();
        $third = $this->variant($this->g5, 'Gorra P3', price: 100, cost: 20);
        $this->order('H', OrderStatus::Delivered, 400, '2026-10-14 10:00:00', items: [[$third, 4, 100]]);

        $this->panelAs($this->staffG5, $this->g5);

        // 7 días (sin el cancelado ni lo de otra tienda): Gorra 4, Tenis 3 (2 + 1), Playera 1.
        $rows = app(SalesReport::class)->topProducts($this->g5, ...SalesPeriod::Week->range());
        $this->assertSame([['Gorra P3', 4, 400.0], ['Tenis P1', 3, 1500.0], ['Playera P2', 1, 200.0]], array_map(fn ($r) => [$r['name'], $r['pieces'], $r['revenue']], $rows));

        Livewire::test(TopProducts::class, ['pageFilters' => ['period' => 'week']])
            ->assertSee('Más vendidos')
            ->assertSeeInOrder(['Gorra P3', 'Tenis P1', 'Playera P2'])
            ->assertDontSee('Ajeno');

        // Hoy solo se vendió el Tenis (2) y la Playera (1).
        Livewire::test(TopProducts::class, ['pageFilters' => ['period' => 'today']])
            ->assertSeeInOrder(['Tenis P1', 'Playera P2'])
            ->assertDontSee('Gorra P3');
    }

    public function test_top_products_is_limited_to_ten()
    {
        for ($i = 1; $i <= 12; $i++) {
            $variant = $this->variant($this->g5, "Producto {$i}", price: 10, cost: 1);
            $this->order("T-{$i}", OrderStatus::Paid, 10 * $i, '2026-10-15 10:00:00', items: [[$variant, $i, 10]]);
        }

        $rows = app(SalesReport::class)->topProducts($this->g5, ...SalesPeriod::Today->range());

        $this->assertCount(10, $rows);
        $this->assertSame('Producto 12', $rows[0]['name']);
        $this->assertSame('Producto 3', $rows[9]['name']);
    }

    public function test_expiring_reservations_lists_unpaid_orders_under_twelve_hours()
    {
        $soon = $this->order('R-1', OrderStatus::Reserved, 100, expiresAt: '2026-10-15 19:00:00');      // en 5 h
        $overdue = $this->order('R-2', OrderStatus::Reserved, 200, expiresAt: '2026-10-15 12:00:00');   // hace 2 h
        $edge = $this->order('R-3', OrderStatus::Reserved, 300, expiresAt: '2026-10-15 23:59:00');      // en 9 h 59 min
        $later = $this->order('R-4', OrderStatus::Reserved, 400, expiresAt: '2026-10-16 08:00:00');     // en 18 h
        $paid = $this->order('R-5', OrderStatus::Paid, 500, '2026-10-15 13:00:00', expiresAt: '2026-10-15 15:00:00');
        $cancelled = $this->order('R-6', OrderStatus::Cancelled, 600, expiresAt: '2026-10-15 15:00:00');
        $other = $this->order('R-7', OrderStatus::Reserved, 700, store: $this->coopers, expiresAt: '2026-10-15 15:00:00');

        $this->panelAs($this->staffG5, $this->g5);

        Livewire::test(ExpiringReservations::class)
            ->assertCanSeeTableRecords([$overdue, $soon, $edge], inOrder: true)
            ->assertCanNotSeeTableRecords([$later, $paid, $cancelled, $other])
            ->assertSee('Apartados por vencer')
            ->assertSee("/admin/g5/orders/{$soon->id}", escape: false);
    }

    public function test_a_new_web_order_notifies_the_stores_users_only()
    {
        $order = $this->order('W-1', OrderStatus::Reserved, 1234.50, expiresAt: '2026-10-16 10:00:00');

        $this->assertSame(1, $this->staffG5->notifications()->count());
        $this->assertSame(0, $this->staffCoopers->notifications()->count());
        $this->assertSame(1, $this->owner->notifications()->count());

        $data = $this->staffG5->notifications()->first()->data;
        $this->assertSame('Pedido nuevo', $data['title']);
        $this->assertStringContainsString('W-1', $data['body']);
        $this->assertStringContainsString('$1,234.50', $data['body']);
        $this->assertStringContainsString('G5 Esports', $data['body']);
        $this->assertStringContainsString("/admin/g5/orders/{$order->id}", $data['actions'][0]['url']);

        // Un pedido de otra tienda avisa a la otra tienda.
        $this->order('W-2', OrderStatus::Reserved, 50, store: $this->coopers);
        $this->assertSame(1, $this->staffG5->notifications()->count());
        $this->assertSame(1, $this->staffCoopers->notifications()->count());
    }

    public function test_counter_sales_do_not_notify()
    {
        $this->order('M-1', OrderStatus::Paid, 300, '2026-10-15 10:00:00', channel: OrderChannel::Counter);

        $this->assertSame(0, $this->staffG5->notifications()->count());
        $this->assertSame(0, $this->owner->notifications()->count());
    }

    public function test_orders_pages_are_scoped_to_the_store()
    {
        $mine = $this->order('G5-001', OrderStatus::Paid, 1000, '2026-10-15 10:00:00', items: [[$this->expensive, 2, 500]]);
        $theirs = $this->order('CP-001', OrderStatus::Paid, 80, '2026-10-15 10:00:00', store: $this->coopers);

        $this->actingAs($this->staffG5);

        $this->get('/admin/g5/orders')->assertOk();
        $this->get("/admin/g5/orders/{$mine->id}")
            ->assertOk()
            ->assertSee('G5-001')
            ->assertSee('Tenis P1')
            ->assertSee('$1,000.00');
        $this->get("/admin/g5/orders/{$theirs->id}")->assertNotFound();
    }

    public function test_dashboard_renders_the_sales_widgets_and_the_period_selector()
    {
        foreach ([$this->owner, $this->staffG5] as $user) {
            $this->actingAs($user)
                ->get('/admin/g5')
                ->assertOk()
                ->assertSee('Periodo de ventas')
                ->assertSeeLivewire(SalesStats::class)
                ->assertSeeLivewire(SalesByDayChart::class)
                ->assertSeeLivewire(OrdersByStatusChart::class)
                ->assertSeeLivewire(SalesByChannelChart::class)
                ->assertSeeLivewire(LatestOrders::class)
                ->assertSeeLivewire(TopProducts::class)
                ->assertSeeLivewire(ExpiringReservations::class);
        }

        $this->assertSame(
            ['Hoy', '7 días', '30 días', 'Este mes'],
            array_map(fn (SalesPeriod $period) => $period->getLabel(), SalesPeriod::cases()),
        );
    }
}
