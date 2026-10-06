<?php

namespace Tests\Feature\Admin;

use App\Enums\CancelledBy;
use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SizeType;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Exceptions\InvalidOrderTransitionException;
use App\Filament\Pages\CounterSale;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\CounterSaleException;
use App\Services\OrderCancellationService;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

class CounterSaleTest extends TestCase
{
    use RefreshDatabase;

    private Store $coppers;

    private Store $g5;

    private User $owner;

    private User $coppersStaff;

    private User $g5Staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);
        $this->owner = User::factory()->create(['name' => 'Dueña Olga', 'role' => UserRole::Owner]);
        $this->coppersStaff = User::factory()->create(['name' => 'Laura Staff', 'role' => UserRole::Staff]);
        $this->coppersStaff->stores()->attach($this->coppers->id);
        $this->g5Staff = User::factory()->create(['name' => 'Gabriel Staff', 'role' => UserRole::Staff]);
        $this->g5Staff->stores()->attach($this->g5->id);
    }

    private function variant(Store $store, string $name, int $stock = 5, float $price = 500, array $extra = [], string $size = 'M'): ProductVariant
    {
        $category = Category::firstOrCreate(
            ['store_id' => $store->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        $product = $category->products()->withoutGlobalScopes()->firstOrCreate(
            ['store_id' => $store->id, 'slug' => str($name)->slug()->toString()],
            ['name' => $name, 'price' => $price, 'category_id' => $category->id, ...$extra],
        );

        return $product->variants()->create(['size' => $size, 'stock' => $stock]);
    }

    /** @param list<array{0: ProductVariant, 1: int}> $lines */
    private function sell(array $lines, ?User $user = null, ?Store $store = null, PaymentMethod $method = PaymentMethod::Cash, mixed $discount = null, ?string $note = null): Order
    {
        return app(OrderService::class)->placeCounterSale(
            $store ?? $this->coppers,
            $user ?? $this->coppersStaff,
            array_map(fn (array $line) => ['variant_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            $method,
            $discount,
            $note,
        );
    }

    private function refused(callable $callback, string $exception = CounterSaleException::class): string
    {
        try {
            $callback();
        } catch (CounterSaleException|InvalidOrderTransitionException $caught) {
            $this->assertInstanceOf($exception, $caught);

            return $caught->getMessage();
        }

        $this->fail('Se esperaba que la operación fuera rechazada.');
    }

    private function nothingHappened(): void
    {
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, OrderItem::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame(0, OrderStatusHistory::count());
    }

    private function panelAs(User $user, Store $store): void
    {
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($store);
    }

    // ---- 1. Una venta descuenta el stock y queda entregada con historial ---------------------------

    public function test_a_sale_takes_the_stock_with_a_counter_sale_movement_and_is_delivered_with_history()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 5, 500);
        $pants = $this->variant($this->coppers, 'Pantalón', 3, 899.90);

        $order = $this->sell([[$shirt, 2], [$pants, 1], [$shirt, 1]], method: PaymentMethod::Card);

        $this->assertSame('CP-00001', $order->number);
        $this->assertSame($this->coppers->id, $order->store_id);
        $this->assertNull($order->customer_id);
        $this->assertSame(OrderChannel::Counter, $order->channel);
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame(DeliveryMethod::Pickup, $order->delivery_method);
        $this->assertSame(PaymentMethod::Card, $order->payment_method);
        $this->assertNull($order->shipping_address);
        $this->assertNull($order->expires_at);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame(['2399.90', '0.00', '0.00', '2399.90'], [$order->subtotal, $order->discount, $order->shipping_cost, $order->total]);

        // La talla repetida es una sola línea con la suma; nada va sobre pedido.
        $items = $order->items()->orderBy('id')->get();
        $this->assertSame(
            [['Camisa', 'M', '500.00', 3, false], ['Pantalón', 'M', '899.90', 1, false]],
            $items->map(fn (OrderItem $item) => [$item->product_name, $item->size, $item->unit_price, $item->quantity, $item->is_backorder])->all(),
        );

        $this->assertSame(2, $shirt->fresh()->stock);
        $this->assertSame(2, $pants->fresh()->stock);

        $movements = StockMovement::orderBy('id')->get();
        $this->assertCount(2, $movements);
        foreach ($movements as $movement) {
            $this->assertSame(StockMovementType::CounterSale, $movement->type);
            $this->assertSame($order->id, $movement->order_id);
            $this->assertSame($this->coppersStaff->id, $movement->user_id);
        }
        $this->assertSame([-3, -1], $movements->pluck('quantity')->all());

        // Historial inicial: nace entregado, lo registró quien atendió.
        $row = $order->statusHistories()->sole();
        $this->assertNull($row->from_status);
        $this->assertSame(OrderStatus::Delivered, $row->to_status);
        $this->assertSame($this->coppersStaff->id, $row->user_id);
        $this->assertStringContainsString('Venta de mostrador', $row->note);
        $this->assertStringContainsString('Tarjeta', $row->note);

        // Numeración compartida con la web y por tienda.
        $this->assertSame('CP-00002', $this->sell([[$shirt, 1]])->number);
        $this->assertSame('G5-00001', $this->sell([[$this->variant($this->g5, 'Balón'), 1]], $this->g5Staff, $this->g5)->number);
    }

    public function test_the_payment_methods_have_spanish_labels()
    {
        $this->assertSame(
            ['Efectivo', 'Transferencia', 'Tarjeta'],
            array_map(fn (PaymentMethod $method) => $method->getLabel(), PaymentMethod::cases()),
        );
    }

    // ---- 2. Sin stock suficiente no crea nada ni descuenta ----------------------------------------

    public function test_without_enough_stock_nothing_is_created_or_taken()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 5);
        // Ni aunque el producto acepte sobre pedido en la web: en mostrador no hay.
        $jacket = $this->variant($this->coppers, 'Chamarra', 2, 1500, ['allow_backorder' => true, 'backorder_days' => 5]);

        $message = $this->refused(fn () => $this->sell([[$shirt, 2], [$jacket, 3]]));

        $this->assertSame('No hay stock suficiente de Chamarra (talla M): quedan 2 piezas y la venta pide 3.', $message);
        $this->nothingHappened();
        $this->assertSame(5, $shirt->fresh()->stock);
        $this->assertSame(2, $jacket->fresh()->stock);

        // Dos líneas de la misma talla suman: 3 + 3 no caben en 5.
        $this->refused(fn () => $this->sell([[$shirt, 3], [$shirt, 3]]));
        $this->nothingHappened();
        $this->assertSame(5, $shirt->fresh()->stock);

        // Tampoco ventas vacías, cantidades en cero o negativas, ni productos apagados o borrados.
        $this->assertSame('Agrega al menos un producto a la venta.', $this->refused(fn () => $this->sell([])));
        $this->refused(fn () => $this->sell([[$shirt, 0]]));
        $this->refused(fn () => $this->sell([[$shirt, -2]]));

        $off = $this->variant($this->coppers, 'Apagado', 5, 500, ['is_active' => false]);
        $this->refused(fn () => $this->sell([[$off, 1]]));

        $deleted = $this->variant($this->coppers, 'Borrado', 5);
        $deleted->delete();
        $this->refused(fn () => $this->sell([[$deleted, 1]]));

        $this->nothingHappened();
        $this->assertSame(5, $off->fresh()->stock);
    }

    // ---- 3. Dos ventas de la última pieza: solo una se registra -----------------------------------

    public function test_two_sales_of_the_last_piece_only_one_is_registered()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 1);

        // Dos cajas tienen la misma última pieza en pantalla y registran una tras otra.
        $first = $this->sell([[$shirt, 1]], $this->coppersStaff);
        $message = $this->refused(fn () => $this->sell([[$shirt, 1]], $this->owner));

        $this->assertSame('No hay stock suficiente de Camisa (talla M): quedan 0 piezas y la venta pide 1.', $message);
        $this->assertSame(1, Order::withoutGlobalScopes()->count());
        $this->assertSame($first->id, Order::withoutGlobalScopes()->sole()->id);
        $this->assertSame(0, $shirt->fresh()->stock);
        $this->assertSame(1, StockMovement::count());
        $this->assertSame(-1, (int) StockMovement::sum('quantity'));

        // Desde la pantalla la talla agotada ya ni se acepta.
        $this->panelAs($this->owner, $this->coppers);

        Livewire::test(CounterSale::class)
            ->set('data.lines', ['a' => ['product_id' => $shirt->product_id, 'variant_id' => $shirt->id, 'quantity' => 1]])
            ->call('register')
            ->assertHasErrors(['data.lines.a.variant_id'])
            ->assertSet('lastNumber', null);

        $this->assertSame(1, Order::withoutGlobalScopes()->count());
    }

    // ---- 4. El precio sale de la base de datos; el descuento no supera el subtotal ----------------

    public function test_the_price_comes_from_the_database_and_the_discount_can_not_exceed_the_subtotal()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 10, 500);

        // Aunque la línea traiga precio, subtotal o total, no se usan.
        $order = app(OrderService::class)->placeCounterSale(
            $this->coppers,
            $this->coppersStaff,
            [['variant_id' => $shirt->id, 'quantity' => 2, 'unit_price' => 1, 'price' => 1, 'line_total' => 2, 'product_name' => 'Regalado']],
            PaymentMethod::Cash,
        );

        $this->assertSame(['1000.00', '1000.00'], [$order->subtotal, $order->total]);
        $this->assertSame(['Camisa', '500.00'], [$order->items()->sole()->product_name, $order->items()->sole()->unit_price]);

        // Lo mismo manipulando la petición de la pantalla.
        $this->panelAs($this->coppersStaff, $this->coppers);

        Livewire::test(CounterSale::class)
            ->set('data.lines', ['a' => ['product_id' => $shirt->product_id, 'variant_id' => $shirt->id, 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1]])
            ->set('data.total', 1)
            ->set('data.subtotal', 1)
            ->call('register')
            ->assertHasNoErrors()
            ->assertSet('lastNumber', 'CP-00002');

        $fromPage = Order::withoutGlobalScopes()->where('number', 'CP-00002')->sole();
        $this->assertSame(['500.00', '500.00', '500.00'], [$fromPage->subtotal, $fromPage->total, $fromPage->items()->sole()->unit_price]);

        // Descuento: es un monto, exige nota y no supera el subtotal.
        $before = Order::withoutGlobalScopes()->count();

        $this->assertSame(
            'El descuento (1000.01) no puede ser mayor que el subtotal (1000.00).',
            $this->refused(fn () => $this->sell([[$shirt, 2]], discount: 1000.01, note: 'Cliente frecuente')),
        );
        $this->assertSame('Escribe una nota que explique el descuento.', $this->refused(fn () => $this->sell([[$shirt, 2]], discount: 100)));
        $this->refused(fn () => $this->sell([[$shirt, 2]], discount: 100, note: '   '));
        $this->refused(fn () => $this->sell([[$shirt, 2]], discount: -50, note: 'Negativo'));
        $this->refused(fn () => $this->sell([[$shirt, 2]], discount: 'mucho', note: 'Texto'));

        $this->assertSame($before, Order::withoutGlobalScopes()->count());
        $this->assertSame(7, $shirt->fresh()->stock);

        $discounted = $this->sell([[$shirt, 2]], discount: '150.50', note: 'Cliente frecuente');
        $this->assertSame(['1000.00', '150.50', '849.50'], [$discounted->subtotal, $discounted->discount, $discounted->total]);
        $this->assertSame('Cliente frecuente', $discounted->internal_notes);
        $this->assertStringContainsString('Cliente frecuente', $discounted->statusHistories()->sole()->note);

        // Descuento igual al subtotal sí se vale (total 0).
        $free = $this->sell([[$shirt, 1]], discount: 500, note: 'Cortesía');
        $this->assertSame('0.00', $free->total);

        // La pantalla pide la nota antes de llegar al servicio.
        Livewire::test(CounterSale::class)
            ->set('data.lines', ['a' => ['product_id' => $shirt->product_id, 'variant_id' => $shirt->id, 'quantity' => 1]])
            ->set('data.discount', 50)
            ->call('register')
            ->assertHasErrors(['data.note']);
    }

    // ---- 5. Dejar una talla en 0 genera la notificación de agotado ---------------------------------

    public function test_a_sale_that_leaves_a_size_at_zero_sends_the_sold_out_notification()
    {
        $small = $this->variant($this->coppers, 'Camisa', 2, 500, size: 'S');
        $medium = $this->variant($this->coppers, 'Camisa', 3, 500, size: 'M');

        $this->sell([[$medium, 2]]);
        $this->assertSame(0, $this->coppersStaff->notifications()->count());

        $this->sell([[$small, 2]]);

        $notice = $this->coppersStaff->notifications()->sole()->data;
        $this->assertSame('Talla agotada', $notice['title']);
        $this->assertStringContainsString('talla S', $notice['body']);
        $this->assertStringContainsString('Camisa', $notice['body']);
        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame(0, $this->g5Staff->notifications()->count());

        $this->sell([[$medium, 1]]);
        $this->assertSame(
            ['Producto agotado', 'Talla agotada'],
            $this->coppersStaff->notifications()->get()->map(fn ($notification) => $notification->data['title'])->sort()->values()->all(),
        );
    }

    // ---- 6. El staff solo vende productos de su tienda ---------------------------------------------

    public function test_staff_only_sells_products_of_their_store()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 5);
        $ball = $this->variant($this->g5, 'Balón', 5);

        // Una variante de la otra tienda se rechaza, aunque vaya junto a una propia.
        $message = $this->refused(fn () => $this->sell([[$shirt, 1], [$ball, 1]], $this->coppersStaff, $this->coppers));
        $this->assertSame("Una de las tallas elegidas no está a la venta en Copper's.", $message);

        // Tampoco puede vender en una tienda que no atiende.
        $this->assertSame('No atiendes la tienda Sports G5.', $this->refused(fn () => $this->sell([[$ball, 1]], $this->coppersStaff, $this->g5)));

        // Ni el owner mezcla tiendas en una venta.
        $this->refused(fn () => $this->sell([[$ball, 1]], $this->owner, $this->coppers));

        $this->nothingHappened();
        $this->assertSame([5, 5], [$shirt->fresh()->stock, $ball->fresh()->stock]);

        // El owner sí vende en cualquiera de las dos, cada producto en su tienda.
        $this->assertSame('G5-00001', $this->sell([[$ball, 1]], $this->owner, $this->g5)->number);

        // La pantalla está filtrada por tienda: no ofrece ni acepta productos de la otra.
        $this->panelAs($this->coppersStaff, $this->coppers);

        Livewire::test(CounterSale::class)
            ->set('data.lines', ['a' => ['product_id' => $ball->product_id, 'variant_id' => $ball->id, 'quantity' => 1]])
            ->call('register')
            ->assertHasErrors(['data.lines.a.variant_id'])
            ->assertSet('lastNumber', null);

        $this->assertSame(4, $ball->fresh()->stock);
        $this->assertSame(0, Order::withoutGlobalScopes()->where('store_id', $this->coppers->id)->count());

        // Y no entra a la página de la tienda ajena.
        $this->get('/admin/g5/venta-de-mostrador')->assertNotFound();
        $this->get('/admin/coppers/venta-de-mostrador')->assertOk()->assertSee('Venta de mostrador')->assertSee('Registrar venta');
    }

    // ---- Pantalla ---------------------------------------------------------------------------------

    public function test_the_page_registers_the_sale_shows_its_number_and_leaves_the_form_clean()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 4, 500, ['color' => 'Azul']);
        $soldOutSize = $this->variant($this->coppers, 'Camisa', 0, 500, size: 'L');
        $this->panelAs($this->coppersStaff, $this->coppers);

        $page = Livewire::test(CounterSale::class)
            ->assertSee('Registrar venta')
            ->set('data.lines', ['a' => ['product_id' => $shirt->product_id, 'variant_id' => null, 'quantity' => 2]])
            // Solo las tallas con stock, con las piezas que quedan; y el precio y subtotal de la línea.
            ->assertSee('M (quedan 4 piezas)')
            ->assertDontSee('L (quedan 0 piezas)')
            ->assertSee('$500.00')
            ->assertSee('$1,000.00')
            ->set('data.lines.a.variant_id', $shirt->id)
            ->set('data.payment_method', PaymentMethod::Transfer->value)
            ->set('data.discount', 100)
            ->set('data.note', 'Promoción del día')
            ->assertSee('$900.00')
            ->call('register')
            ->assertHasNoErrors()
            ->assertNotified('Venta registrada: CP-00001')
            ->assertSet('lastNumber', 'CP-00001')
            ->assertSee('CP-00001')
            ->assertSet('data.discount', null)
            ->assertSet('data.note', null)
            ->assertSet('data.payment_method', PaymentMethod::Cash->value);

        // Formulario limpio: una sola línea vacía.
        $lines = $page->get('data.lines');
        $this->assertCount(1, $lines);
        $this->assertNull(array_values($lines)[0]['product_id']);

        $order = Order::withoutGlobalScopes()->sole();
        $this->assertSame(PaymentMethod::Transfer, $order->payment_method);
        $this->assertSame(['1000.00', '100.00', '900.00'], [$order->subtotal, $order->discount, $order->total]);
        $this->assertSame(2, $shirt->fresh()->stock);
        $this->assertSame(0, $soldOutSize->fresh()->stock);

        // Sin líneas completas no registra.
        Livewire::test(CounterSale::class)->call('register')->assertHasErrors();
        $this->assertSame(1, Order::withoutGlobalScopes()->count());

        // En el recurso Pedidos aparece con canal y cliente «Mostrador».
        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->assertTableColumnStateSet('channel', OrderChannel::Counter, $order)
            ->assertTableColumnStateSet('customer.name', null, $order)
            ->assertSee('Mostrador');

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertSee('Mostrador')
            ->assertSee('Transferencia')
            ->assertSee('Entregado');
    }

    // ---- 7. El owner anula una venta de mostrador y regresa el stock; el staff no -----------------

    public function test_the_owner_can_void_a_counter_sale_and_the_stock_returns_but_staff_can_not()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 5);
        $order = $this->sell([[$shirt, 2]]);
        $this->assertSame(3, $shirt->fresh()->stock);

        // Staff: ni ve la acción ni puede por el servicio.
        $this->panelAs($this->coppersStaff, $this->coppers);
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('voidSale')
            ->assertActionHidden('cancel');

        $message = $this->refused(
            fn () => app(OrderCancellationService::class)->voidCounterSale($order, $this->coppersStaff, 'Se equivocó'),
            InvalidOrderTransitionException::class,
        );
        $this->assertSame('Solo el dueño puede anular la venta CP-00001.', $message);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame(3, $shirt->fresh()->stock);

        // Owner: exige motivo.
        $this->panelAs($this->owner, $this->coppers);
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('voidSale')
            ->assertActionHasLabel('voidSale', 'Anular venta')
            ->callAction('voidSale', data: ['reason' => ''])
            ->assertHasFormErrors(['reason' => 'required']);
        $this->refused(fn () => app(OrderCancellationService::class)->voidCounterSale($order, $this->owner, '  '), InvalidOrderTransitionException::class);
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);

        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('voidSale', data: ['reason' => 'El cliente regresó la camisa'])
            ->assertHasNoFormErrors()
            ->assertNotified('Venta anulada');

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(CancelledBy::Admin, $order->cancelled_by);
        $this->assertSame('El cliente regresó la camisa', $order->cancel_reason);
        $this->assertNotNull($order->cancelled_at);
        $this->assertNotNull($order->refunded_at);
        $this->assertFalse($order->refundPending());

        // Las piezas regresan con un movimiento de cancelación ligado a la venta.
        $this->assertSame(5, $shirt->fresh()->stock);
        $movement = StockMovement::where('type', StockMovementType::Cancellation->value)->sole();
        $this->assertSame([2, $order->id, $this->owner->id], [$movement->quantity, $movement->order_id, $movement->user_id]);

        $row = $order->statusHistories()->get()->last();
        $this->assertSame([OrderStatus::Delivered, OrderStatus::Cancelled], [$row->from_status, $row->to_status]);
        $this->assertSame($this->owner->id, $row->user_id);
        $this->assertSame('Venta anulada: El cliente regresó la camisa', $row->note);

        // Anularla otra vez no regresa el stock dos veces.
        Livewire::test(ViewOrder::class, ['record' => $order->getRouteKey()])->assertActionHidden('voidSale');
        $this->refused(fn () => app(OrderCancellationService::class)->voidCounterSale($order, $this->owner, 'Otra vez'), InvalidOrderTransitionException::class);
        $this->assertSame(5, $shirt->fresh()->stock);
        $this->assertSame(1, StockMovement::where('type', StockMovementType::Cancellation->value)->count());
    }

    // ---- 8. No se puede anular un pedido entregado de canal web -----------------------------------

    public function test_a_delivered_web_order_can_not_be_voided()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 5);
        $ana = Customer::factory()->create();

        $web = Order::create([
            'store_id' => $this->coppers->id, 'customer_id' => $ana->id, 'number' => 'CP-00090', 'channel' => OrderChannel::Web,
            'status' => OrderStatus::Delivered, 'delivery_method' => DeliveryMethod::Pickup, 'subtotal' => 500, 'total' => 500,
            'paid_at' => now(), 'delivered_at' => now(),
        ]);
        $web->items()->create(['product_variant_id' => $shirt->id, 'product_name' => 'Camisa', 'size' => 'M', 'unit_price' => 500, 'quantity' => 1, 'is_backorder' => false]);

        $message = $this->refused(
            fn () => app(OrderCancellationService::class)->voidCounterSale($web, $this->owner, 'Devolución'),
            InvalidOrderTransitionException::class,
        );
        $this->assertSame('El pedido CP-00090 no se puede anular: solo las ventas de mostrador entregadas.', $message);

        // Tampoco por la cancelación normal ni por un cambio de estado: entregado es final.
        $this->refused(fn () => app(OrderCancellationService::class)->cancel($web, $this->owner, 'Devolución'), InvalidOrderTransitionException::class);
        $this->refused(fn () => app(OrderStatusService::class)->transition($web, OrderStatus::Cancelled, $this->owner), InvalidOrderTransitionException::class);

        $this->assertSame(OrderStatus::Delivered, $web->fresh()->status);
        $this->assertSame(5, $shirt->fresh()->stock);
        $this->assertSame(0, StockMovement::count());

        $this->panelAs($this->owner, $this->coppers);
        Livewire::test(ViewOrder::class, ['record' => $web->getRouteKey()])
            ->assertActionHidden('voidSale')
            ->assertActionHidden('cancel');

        // Y una venta de mostrador tampoco se cancela por el camino normal: solo se anula.
        $counter = $this->sell([[$shirt, 1]]);
        $this->refused(fn () => app(OrderCancellationService::class)->cancel($counter, $this->owner, 'Por error'), InvalidOrderTransitionException::class);
        $this->assertSame(OrderStatus::Delivered, $counter->fresh()->status);
    }

    // ---- 9. No cuenta para el límite de apartados ni aparece en el historial de ningún cliente ----

    public function test_a_counter_sale_does_not_count_for_the_reservation_limit_or_show_in_any_customers_orders()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 20);
        $ana = Customer::factory()->create(['name' => 'Ana Pérez']);
        $host = 'http://coppers.localhost';
        $reserve = function () use ($shirt, $ana, $host) {
            $this->actingAs($ana, 'customer');
            $this->post("{$host}/carrito", ['variant_id' => $shirt->id, 'quantity' => 1])->assertSessionHasNoErrors();

            return $this->post("{$host}/checkout", ['delivery_method' => 'recoger', 'phone' => '755 123 4567']);
        };

        $reserve()->assertSessionHasNoErrors();
        $reserve()->assertSessionHasNoErrors();

        // Varias ventas de mostrador en medio: muchas más que el límite.
        $sales = collect(range(1, 4))->map(fn () => $this->sell([[$shirt, 1]]));

        $this->assertNull(app(OrderService::class)->reservationLimitMessage($this->coppers, $ana));
        $reserve()->assertSessionHasNoErrors();
        $this->assertSame(3, Order::withoutGlobalScopes()->where('customer_id', $ana->id)->count());
        $this->assertSame(4, Order::withoutGlobalScopes()->whereNull('customer_id')->count());

        // El cuarto apartado sí choca con el límite: cuentan sus 3, no las ventas de mostrador.
        $reserve()->assertSessionHasErrors(['reservations' => "Ya tienes 3 pedidos apartados sin pagar en Copper's. Paga o cancela alguno para poder apartar otro."]);

        // «Mis pedidos»: solo sus tres apartados; la venta de mostrador no se puede abrir.
        $this->get("{$host}/mi-cuenta/pedidos")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 3)
            ->where('orders', fn ($orders) => collect($orders)->pluck('number')->intersect($sales->pluck('number'))->isEmpty()));

        foreach ($sales as $sale) {
            $this->get("{$host}/mi-cuenta/pedidos/{$sale->number}")->assertNotFound();
            $this->get("{$host}/pedido/{$sale->number}/gracias")->assertNotFound();
        }

        $this->assertSame(0, Order::withoutGlobalScopes()->where('channel', OrderChannel::Counter->value)->whereNotNull('customer_id')->count());
    }
}
