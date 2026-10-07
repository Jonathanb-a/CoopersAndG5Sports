<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SizeType;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Jobs\SendTelegramMessage;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\CancellationRequestService;
use App\Services\OrderService;
use App\Services\OrderStatusService;
use App\Services\StockService;
use App\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramNotifierTest extends TestCase
{
    use RefreshDatabase;

    private const COPPERS = 'http://coppers.localhost';

    private const G5 = 'http://g5.localhost';

    private const TOKEN = '123456:SECRET-bot-token';

    private Store $coppers;

    private Store $g5;

    private Customer $ana;

    private User $owner;

    /** @var callable(Request): mixed */
    private $telegram;

    protected function setUp(): void
    {
        parent::setUp();

        $this->telegram = fn () => Http::response(['ok' => true]);

        $this->withoutVite();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers', 'local_shipping_cost' => 80]);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);
        $this->ana = Customer::factory()->create(['name' => 'Ana Pérez']);
        $this->owner = User::factory()->create(['role' => UserRole::Owner]);

        config(['services.telegram.bot_token' => self::TOKEN, 'services.telegram.owner_chat_id' => '99887766']);

        Http::preventStrayRequests();
        // Lo que contesta Telegram en cada prueba; por omisión, que sí lo recibió.
        Http::fake(fn (Request $request) => ($this->telegram)($request));
    }

    private function variant(Store $store, string $name, int $stock = 5, string $size = 'M', array $extra = []): ProductVariant
    {
        $category = Category::firstOrCreate(
            ['store_id' => $store->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        $product = $category->products()->withoutGlobalScopes()->firstOrCreate(
            ['store_id' => $store->id, 'slug' => str($name)->slug()->toString()],
            ['name' => $name, 'price' => 500, 'cost' => 123.45, 'category_id' => $category->id, ...$extra],
        );

        return $product->variants()->create(['size' => $size, 'stock' => $stock]);
    }

    /** Aparta por la tienda en línea y devuelve la respuesta. */
    private function checkout(ProductVariant $variant, int $quantity = 1, string $host = self::COPPERS, array $data = [])
    {
        $this->actingAs($this->ana, 'customer');
        $this->post("{$host}/carrito", ['variant_id' => $variant->id, 'quantity' => $quantity])->assertSessionHasNoErrors();

        return $this->post("{$host}/checkout", ['delivery_method' => 'recoger', 'phone' => '755 123 4567', ...$data]);
    }

    /** Textos mandados a Telegram, en orden. */
    private function sent(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0]['text'])->all();
    }

    // ---- Servicio ---------------------------------------------------------------------------------

    public function test_send_posts_the_message_to_the_owners_chat_with_a_short_timeout()
    {
        $this->assertTrue(app(TelegramNotifier::class)->send('Hola dueño'));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'
            && $request->method() === 'POST'
            && $request['chat_id'] === '99887766'
            && $request['text'] === 'Hola dueño');
    }

    public function test_the_config_reads_the_two_env_variables()
    {
        $source = file_get_contents(config_path('services.php'));

        $this->assertStringContainsString("'bot_token' => env('TELEGRAM_BOT_TOKEN')", $source);
        $this->assertStringContainsString("'owner_chat_id' => env('TELEGRAM_OWNER_CHAT_ID')", $source);

        $example = file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^TELEGRAM_BOT_TOKEN=$/m', $example);
        $this->assertMatchesRegularExpression('/^TELEGRAM_OWNER_CHAT_ID=$/m', $example);
    }

    // ---- Sin token o sin chat id: no se manda nada y no hay error ---------------------------------

    public function test_without_token_or_chat_id_nothing_is_sent_and_nothing_breaks()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 10);

        $missing = [
            ['services.telegram.bot_token' => null],
            ['services.telegram.owner_chat_id' => null],
            ['services.telegram.bot_token' => '', 'services.telegram.owner_chat_id' => '  '],
        ];

        foreach ($missing as $position => $config) {
            config(['services.telegram.bot_token' => self::TOKEN, 'services.telegram.owner_chat_id' => '99887766', ...$config]);
            Log::spy();

            $this->assertFalse(app(TelegramNotifier::class)->configured());
            $this->assertFalse(app(TelegramNotifier::class)->send('Hola'));
            app(TelegramNotifier::class)->queue('Hola');

            // Y un pedido de verdad se crea igual.
            $this->checkout($shirt)->assertSessionHasNoErrors();
            $this->assertSame($position + 1, Order::withoutGlobalScopes()->count());

            Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'Telegram sin configurar'))->atLeast()->once();
            Log::shouldNotHaveReceived('error');
        }

        Http::assertNothingSent();
    }

    public function test_without_config_no_job_is_queued()
    {
        config(['services.telegram.bot_token' => null]);
        Queue::fake();

        app(TelegramNotifier::class)->queue('Hola');

        Queue::assertNothingPushed();
    }

    // ---- Si Telegram falla, el pedido igual se crea -----------------------------------------------

    public function test_if_telegram_answers_an_error_or_throws_the_order_is_still_created()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 10);

        // Telegram responde error.
        $this->telegram = fn () => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401);
        Log::spy();

        $this->assertFalse(app(TelegramNotifier::class)->send('Hola'));
        $this->checkout($shirt)->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');

        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'HTTP 401'))->atLeast()->once();
        $this->assertSame(OrderStatus::Reserved, Order::withoutGlobalScopes()->sole()->status);
        $this->assertSame(9, $shirt->fresh()->stock);
    }

    public function test_if_telegram_does_not_answer_the_order_is_still_created_and_the_token_is_not_logged()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 1);

        $this->telegram = fn (Request $request) => throw new ConnectionException("cURL error 28: timeout for {$request->url()}");
        Log::spy();

        $this->assertFalse(app(TelegramNotifier::class)->send('Hola'));

        // Este pedido además agota el modelo: dos avisos que fallan y nada se rompe.
        $this->checkout($shirt)->assertRedirect(self::COPPERS.'/pedido/CP-00001/gracias');

        $this->assertSame(1, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, $shirt->fresh()->stock);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'No se pudo mandar el aviso de Telegram') && str_contains($message, '[token]') && ! str_contains($message, self::TOKEN))
            ->times(3);
    }

    // ---- Pedido nuevo -----------------------------------------------------------------------------

    public function test_a_new_order_sends_a_notice_with_the_right_store_and_number()
    {
        $this->travelTo('2026-10-06 10:30:00');
        $shirt = $this->variant($this->coppers, 'Camisa', 10);
        $ball = $this->variant($this->g5, 'Balón', 10);

        $this->checkout($shirt, 2, data: [
            'delivery_method' => 'local',
            'address' => ['street' => 'Calle Hidalgo 5', 'neighborhood' => 'Centro', 'city' => 'Zihuatanejo', 'state' => 'Guerrero', 'zip' => '40880'],
        ])->assertSessionHasNoErrors();
        $this->checkout($ball, host: self::G5)->assertSessionHasNoErrors();

        $this->assertSame([
            implode("\n", [
                "Copper's · CP-00001 · Pedido nuevo",
                'Cliente: Ana Pérez',
                'Total: $1,080.00',
                'Entrega: Entrega local',
                'Paga antes de: 08/10/2026 10:30',
            ]),
            implode("\n", [
                'Sports G5 · G5-00001 · Pedido nuevo',
                'Cliente: Ana Pérez',
                'Total: $500.00',
                'Entrega: Recoger en tienda',
                'Paga antes de: 08/10/2026 10:30',
            ]),
        ], $this->sent());
    }

    public function test_the_notice_goes_through_a_queued_job()
    {
        Queue::fake();
        $shirt = $this->variant($this->coppers, 'Camisa', 10);

        $this->checkout($shirt)->assertSessionHasNoErrors();

        Queue::assertPushed(SendTelegramMessage::class, 1);
        Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job) => str_starts_with($job->message, "Copper's · CP-00001 · Pedido nuevo"));
        Http::assertNothingSent();
    }

    public function test_an_order_that_is_not_created_sends_nothing()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 1);

        $this->actingAs($this->ana, 'customer');
        $this->post(self::COPPERS.'/carrito', ['variant_id' => $shirt->id, 'quantity' => 1]);
        $shirt->update(['stock' => 0]);

        $this->post(self::COPPERS.'/checkout', ['delivery_method' => 'recoger', 'phone' => '755 123 4567'])->assertRedirect(self::COPPERS.'/carrito');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    // ---- Venta de mostrador -----------------------------------------------------------------------

    public function test_a_counter_sale_sends_no_new_order_notice()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 10);

        $order = app(OrderService::class)->placeCounterSale(
            $this->coppers, $this->owner, [['variant_id' => $shirt->id, 'quantity' => 2]], PaymentMethod::Cash,
        );

        $this->assertSame('CP-00001', $order->number);
        Http::assertNothingSent();
    }

    // ---- Solicitud de cancelación -----------------------------------------------------------------

    public function test_a_cancellation_request_sends_a_notice()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 10);
        $this->checkout($shirt)->assertSessionHasNoErrors();
        $order = Order::withoutGlobalScopes()->sole();
        app(OrderStatusService::class)->transition($order, OrderStatus::Paid, $this->owner);

        $this->actingAs($this->ana, 'customer')
            ->post(self::COPPERS."/mi-cuenta/pedidos/{$order->number}/solicitar-cancelacion", ['note' => 'Me equivoqué de talla'])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            "Copper's · CP-00001 · Solicitud de cancelación\nCliente: Ana Pérez\nMotivo: Me equivoqué de talla",
            $this->sent()[1],
        );

        // Una segunda solicitud se rechaza y no manda otro aviso.
        $this->post(self::COPPERS."/mi-cuenta/pedidos/{$order->number}/solicitar-cancelacion", ['note' => 'Otra vez']);
        $this->assertCount(2, $this->sent());

        // Sin motivo también avisa.
        $this->checkout($shirt)->assertSessionHasNoErrors();
        $second = Order::withoutGlobalScopes()->orderByDesc('id')->first();
        app(OrderStatusService::class)->transition($second, OrderStatus::Paid, $this->owner);
        app(CancellationRequestService::class)->request($second, $this->ana);

        $this->assertSame("Copper's · CP-00002 · Solicitud de cancelación\nCliente: Ana Pérez\nMotivo: no dio motivo", $this->sent()[3]);
    }

    // ---- Modelo agotado ---------------------------------------------------------------------------

    public function test_a_sold_out_model_notifies_once_and_again_after_restocking_and_selling_out()
    {
        $small = $this->variant($this->g5, 'Tenis Predator', 1, '26', ['color' => 'Negro']);
        $large = $this->variant($this->g5, 'Tenis Predator', 2, '27');
        $stock = app(StockService::class);
        $notice = "Sports G5 · Modelo agotado\nSe agotó Tenis Predator (Negro) en Sports G5.";

        // Se agota una talla, pero queda la otra: todavía no.
        $stock->change($small, StockMovementType::Adjustment, -1);
        $stock->change($large, StockMovementType::Adjustment, -1);
        $this->assertSame([], $this->sent());

        // Se agota la última talla: avisa.
        $stock->change($large, StockMovementType::Adjustment, -1);
        $this->assertSame([$notice], $this->sent());

        // Ya está en 0: un movimiento que no cabe no cambia nada ni repite el aviso.
        try {
            $stock->change($large, StockMovementType::Adjustment, -1);
        } catch (\Throwable) {
        }
        $this->assertCount(1, $this->sent());

        // Se repone una talla y se vende sin agotar: nada.
        $stock->change($small, StockMovementType::Entry, 3);
        $stock->change($small, StockMovementType::Adjustment, -1);
        $this->assertCount(1, $this->sent());

        // Se vuelve a agotar: avisa otra vez.
        $stock->change($small, StockMovementType::Adjustment, -2);
        $this->assertSame([$notice, $notice], $this->sent());
    }

    public function test_an_online_order_that_takes_the_last_piece_sends_both_notices()
    {
        $shirt = $this->variant($this->coppers, 'Camisa', 1);

        $this->checkout($shirt)->assertSessionHasNoErrors();

        $sent = $this->sent();
        sort($sent);

        $this->assertCount(2, $sent);
        $this->assertStringStartsWith("Copper's · CP-00001 · Pedido nuevo", $sent[0]);
        $this->assertSame("Copper's · Modelo agotado\nSe agotó Camisa en Copper's.", $sent[1]);

        // Una venta de mostrador que agota el modelo sí avisa del agotamiento (pero no de la venta).
        $pants = $this->variant($this->coppers, 'Pantalón', 2);
        app(OrderService::class)->placeCounterSale($this->coppers, $this->owner, [['variant_id' => $pants->id, 'quantity' => 2]], PaymentMethod::Card);

        $this->assertCount(3, $this->sent());
        $this->assertSame("Copper's · Modelo agotado\nSe agotó Pantalón en Copper's.", $this->sent()[2]);
    }

    // ---- Ningún mensaje incluye el token ni el costo ----------------------------------------------

    public function test_no_message_includes_the_token_or_the_product_cost()
    {
        // Costo 123.45 en todos los productos de la prueba (ver variant()).
        $shirt = $this->variant($this->coppers, 'Camisa', 2);

        $this->checkout($shirt, 2)->assertSessionHasNoErrors();
        $order = Order::withoutGlobalScopes()->sole();
        app(OrderStatusService::class)->transition($order, OrderStatus::Paid, $this->owner);
        app(CancellationRequestService::class)->request($order, $this->ana, 'Ya no la quiero');

        $messages = $this->sent();
        $this->assertCount(3, $messages); // pedido nuevo, modelo agotado y solicitud de cancelación

        foreach ($messages as $message) {
            $this->assertStringNotContainsString(self::TOKEN, $message);
            $this->assertStringNotContainsString('SECRET', $message);
            $this->assertStringNotContainsString('123.45', $message);
            $this->assertStringNotContainsStringIgnoringCase('cost', $message);
            $this->assertStringNotContainsString('99887766', $message);
        }
    }
}
