<?php

namespace Tests\Feature;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SizeType;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderCancellationService;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Support\OrderWhatsAppMessage;
use App\Support\TelegramMessage;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use Tests\TestCase;

/** Forma de pago en el checkout: transferencia (con comprobante por WhatsApp) o efectivo al entregar. */
class CashPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const COPPERS = 'http://coppers.localhost';

    private Store $coppers;

    private Customer $ana;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->coppers = Store::create([
            'name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers',
            'whatsapp_phone' => '755 111 1111', 'bank_info' => 'BBVA CLABE 0123', 'local_shipping_cost' => 80,
        ]);
        $this->ana = Customer::factory()->create(['name' => 'Ana Pérez']);
        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
    }

    private function variant(int $stock = 10): ProductVariant
    {
        $category = Category::firstOrCreate(
            ['store_id' => $this->coppers->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        $count = Order::withoutGlobalScopes()->count() + ProductVariant::count();

        return $category->products()
            ->create(['store_id' => $this->coppers->id, 'name' => "Camisa {$count}", 'slug' => "camisa-{$count}", 'price' => 500])
            ->variants()->create(['size' => 'M', 'stock' => $stock]);
    }

    /** Aparta un carrito de una pieza con los datos de checkout que se indiquen. */
    private function place(array $data = [], ?ProductVariant $variant = null)
    {
        $this->actingAs($this->ana, 'customer');
        $this->post(self::COPPERS.'/carrito', ['variant_id' => ($variant ?? $this->variant())->id, 'quantity' => 1])->assertSessionHasNoErrors();

        return $this->post(self::COPPERS.'/checkout', ['delivery_method' => 'recoger', 'phone' => '755 123 4567', ...$data]);
    }

    private function localData(array $extra = []): array
    {
        return ['delivery_method' => 'local', 'local' => ['name' => 'Ana Pérez', 'point' => 'Hotel Sol, lobby']] + $extra;
    }

    private function last(): Order
    {
        return Order::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function props(string $url): array
    {
        $props = [];

        $this->actingAs($this->ana, 'customer')->get($url)->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function panel(): void
    {
        $this->actingAs($this->owner, 'web');
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($this->coppers);
    }

    // ---- Servidor ---------------------------------------------------------------------------------

    public function test_the_customer_chooses_transfer_or_cash_and_without_a_choice_it_is_transfer()
    {
        $this->travelTo('2026-10-07 10:00:00');

        $this->place(['payment_method' => 'transfer'])->assertSessionHasNoErrors();
        $transfer = $this->last();
        $this->assertSame(PaymentMethod::Transfer, $transfer->payment_method);
        $this->assertSame('2026-10-09 10:00:00', $transfer->expires_at->toDateTimeString());

        $this->place(['payment_method' => 'cash'])->assertSessionHasNoErrors();
        $cash = $this->last();
        $this->assertSame(PaymentMethod::Cash, $cash->payment_method);
        $this->assertTrue($cash->paysInCash());
        // En efectivo vence a las 72 h si no lo confirman (transferencia: 48 h).
        $this->assertSame('2026-10-10 10:00:00', $cash->expires_at->toDateTimeString());
        $this->assertSame(OrderStatus::Reserved, $cash->status);

        // Sin dato (clientes de antes de este campo) se paga por transferencia.
        $this->place()->assertSessionHasNoErrors();
        $this->assertSame(PaymentMethod::Transfer, $this->last()->payment_method);
        $this->assertNotNull($this->last()->expires_at);
    }

    public function test_only_transfer_and_cash_are_accepted()
    {
        $variant = $this->variant();

        foreach (['card', 'efectivo', 'oxxo', 'delivery'] as $method) {
            $this->place(['payment_method' => $method], $variant)
                ->assertSessionHasErrors(['payment_method' => 'Elige si vas a pagar con transferencia o en efectivo.']);
        }

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(10, $variant->fresh()->stock);

        // Tampoco desde el servicio.
        $this->expectException(\InvalidArgumentException::class);
        app(OrderService::class)->place($this->coppers, $this->ana, DeliveryMethod::Pickup, null, '7551234567', null, PaymentMethod::Card);
    }

    public function test_a_cash_order_waits_longer_than_a_transfer_order_before_expiring()
    {
        $this->travelTo('2026-10-07 10:00:00');
        $cashVariant = $this->variant();
        $transferVariant = $this->variant();

        $this->place(['payment_method' => 'cash'], $cashVariant);
        $cash = $this->last();
        $this->place(['payment_method' => 'transfer'], $transferVariant);
        $transfer = $this->last();

        // A los 2 días y 1 hora: la transferencia (48 h) venció; el efectivo (72 h) todavía espera.
        $this->travelTo('2026-10-09 11:00:00');
        $this->artisan('orders:expire-unpaid')->expectsOutputToContain('1 pedidos cancelados')->assertSuccessful();

        $this->assertSame(OrderStatus::Reserved, $cash->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $transfer->fresh()->status);
        $this->assertSame(9, $cashVariant->fresh()->stock);
        $this->assertSame(10, $transferVariant->fresh()->stock);
    }

    public function test_cash_orders_still_count_for_the_reservation_limit()
    {
        $variant = $this->variant(20);

        foreach (range(1, 3) as $ignored) {
            $this->place(['payment_method' => 'cash'], $variant)->assertSessionHasNoErrors();
        }

        $this->place(['payment_method' => 'cash'], $variant)
            ->assertSessionHasErrors('reservations');
        $this->assertSame(3, Order::withoutGlobalScopes()->count());
    }

    // ---- Lo que ve el cliente --------------------------------------------------------------------

    public function test_the_thanks_page_asks_for_the_receipt_on_transfer_and_for_cash_ready_on_delivery()
    {
        $this->place(['payment_method' => 'transfer', ...$this->localData()]);
        $transfer = $this->props(self::COPPERS.'/pedido/CP-00001/gracias');

        $this->assertSame('transfer', $transfer['order']['payment_method']);
        // Transferencia: datos de la cuenta, plazo y botón del comprobante con su número de pedido.
        $this->assertSame('BBVA CLABE 0123', $transfer['bankInfo']);
        $this->assertNotNull($transfer['order']['expires_at']);
        $this->assertStringContainsString(rawurlencode('Pedido: CP-00001'), $transfer['whatsapp']['receipt_url']);

        $this->place(['payment_method' => 'cash', ...$this->localData()]);
        $cash = $this->props(self::COPPERS.'/pedido/CP-00002/gracias');

        $this->assertSame('cash', $cash['order']['payment_method']);
        $this->assertSame('local', $cash['order']['delivery_method']);
        // Efectivo: sin comprobante (solo el botón de dudas), con su fecha para que lo confirmen.
        $this->assertNotNull($cash['order']['expires_at']);
        $this->assertNotNull($cash['order']['expires_at_text']);
        $this->assertNull($cash['whatsapp']['receipt_url']);
        $this->assertNotNull($cash['whatsapp']['question_url']);
    }

    public function test_the_order_detail_keeps_the_cash_reminder_until_it_is_delivered()
    {
        $this->place(['payment_method' => 'cash']);
        $order = $this->last();

        $props = $this->props(self::COPPERS.'/mi-cuenta/pedidos/CP-00001');
        $this->assertSame('cash', $props['order']['payment_method']);
        $this->assertNotNull($props['order']['expires_at']);
        $this->assertNull($props['whatsapp']['receipt_url']);

        // Un pedido de transferencia sí lleva el botón del comprobante mientras está apartado.
        $this->place(['payment_method' => 'transfer']);
        $transfer = $this->props(self::COPPERS.'/mi-cuenta/pedidos/CP-00002');
        $this->assertSame('transfer', $transfer['order']['payment_method']);
        $this->assertNotNull($transfer['whatsapp']['receipt_url']);

        // Un pedido de antes de existir la forma de pago se trata como transferencia.
        $order->forceFill(['payment_method' => null])->save();
        $this->assertSame('transfer', $this->props(self::COPPERS.'/mi-cuenta/pedidos/CP-00001')['order']['payment_method']);
    }

    // ---- Avisos -----------------------------------------------------------------------------------

    public function test_the_telegram_notice_says_how_the_order_is_paid()
    {
        $this->travelTo('2026-10-07 10:00:00');

        $this->place(['payment_method' => 'cash', ...$this->localData()]);
        $text = TelegramMessage::newOrder($this->last(), $this->coppers, $this->ana);
        $this->assertStringContainsString("Entrega: Entrega local\n", $text);
        $this->assertStringContainsString('Pago: Efectivo (se cobra al entregar)', $text);
        $this->assertStringNotContainsString('Paga antes de', $text);
        $this->assertStringContainsString('Confirmar antes de: 10/10/2026 10:00', $text);

        $this->place(['payment_method' => 'cash']);
        $this->assertStringContainsString('Pago: Efectivo (se cobra al recoger)', TelegramMessage::newOrder($this->last(), $this->coppers, $this->ana));

        $this->place(['payment_method' => 'transfer']);
        $text = TelegramMessage::newOrder($this->last(), $this->coppers, $this->ana);
        $this->assertStringContainsString("Pago: Transferencia\nPaga antes de: 09/10/2026 10:00", $text);
    }

    public function test_the_paid_whatsapp_notice_of_a_cash_order_does_not_thank_for_a_payment_yet()
    {
        $owner = $this->owner;
        $this->place(['payment_method' => 'cash']);
        $cash = $this->last();
        $this->place(['payment_method' => 'transfer']);
        $transfer = $this->last();

        foreach ([$cash, $transfer] as $order) {
            app(OrderStatusService::class)->transition($order, OrderStatus::Paid, $owner);
        }

        $cashText = OrderWhatsAppMessage::statusNotice($cash->fresh()->load('store', 'customer'));
        $this->assertStringContainsString('confirmamos tu pedido CP-00001', $cashText);
        $this->assertStringContainsString('Pagas $500.00 en efectivo al recoger tu pedido.', $cashText);
        $this->assertStringContainsString('Ten el efectivo a la mano.', $cashText);
        $this->assertStringNotContainsString('recibimos el pago', $cashText);

        $this->assertStringContainsString('recibimos el pago de tu pedido CP-00002', OrderWhatsAppMessage::statusNotice($transfer->fresh()->load('store', 'customer')));
    }

    // ---- Panel ------------------------------------------------------------------------------------

    public function test_the_panel_confirms_a_cash_order_without_a_reference()
    {
        $this->place(['payment_method' => 'cash']);
        $cash = $this->last();
        $this->place(['payment_method' => 'transfer']);
        $transfer = $this->last();
        $this->panel();

        // Transferencia: exige referencia y se puede dar más tiempo.
        Livewire::test(ViewOrder::class, ['record' => $transfer->getRouteKey()])
            ->assertActionVisible('extend')
            ->callAction('markPaid', data: [])
            ->assertHasFormErrors(['payment_reference']);
        $this->assertSame(OrderStatus::Reserved, $transfer->fresh()->status);

        // Efectivo: también se le puede dar más tiempo, y confirmarlo no pide referencia.
        Livewire::test(ViewOrder::class, ['record' => $cash->getRouteKey()])
            ->assertActionVisible('extend')
            ->assertSee('Efectivo')
            ->callAction('markPaid', data: [])
            ->assertHasNoFormErrors();

        $cash->refresh();
        $this->assertSame(OrderStatus::Paid, $cash->status);
        $this->assertNotNull($cash->paid_at);
        $this->assertNull($cash->payment_reference);
        $this->assertSame(PaymentMethod::Cash, $cash->payment_method);
    }

    public function test_cancelling_a_cash_order_returns_the_stock()
    {
        $variant = $this->variant();
        $this->place(['payment_method' => 'cash'], $variant);
        $this->assertSame(9, $variant->fresh()->stock);

        app(OrderCancellationService::class)->cancel($this->last(), $this->owner, 'Cliente pidió cancelar');

        $this->assertSame(10, $variant->fresh()->stock);
        $this->assertSame(1, StockMovement::where('type', StockMovementType::Cancellation->value)->count());
    }
}
