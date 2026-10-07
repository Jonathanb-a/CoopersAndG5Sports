<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMethod;
use App\Http\Middleware\ResolveStore;
use App\Models\Customer;
use App\Models\Store;
use App\Services\CartChangedException;
use App\Services\CartService;
use App\Services\CouponResult;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Services\ReservationLimitException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checkout: resumen, entrega, contacto y «Apartar pedido». Exige cliente con sesión.
 * Del navegador solo se aceptan el método de entrega (recoger o entrega local), los datos de la
 * entrega local, el teléfono y las notas:
 * líneas, precios, descuento y envío los calcula el servidor.
 */
class CheckoutController extends Controller
{
    /** La entrega local es solo en Zihuatanejo. */
    public const LOCAL_CITY = 'Zihuatanejo';

    public function __construct(
        private readonly CartService $cart,
        private readonly CouponService $coupons,
        private readonly OrderService $orders,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $store = $this->currentStore();
        $customer = $request->user('customer');

        $view = $this->cart->reconcile($store);

        // Sin carrito, o si algo cambió (se agotó, cambió el precio...), se revisa primero en /carrito.
        if ($view->isEmpty() || $view->notices !== []) {
            return to_route('cart.show')->with('cart_notices', $view->notices);
        }

        $code = session(CouponService::SESSION_KEY.'.code');
        $applied = $this->coupons->applied($store, $view->subtotal());

        if ($applied && ! $applied->valid) {
            return to_route('cart.show')->with('cart_notices', ["Quitamos el cupón {$code}: {$applied->reason}"]);
        }

        return Inertia::render('checkout', [
            'cart' => [
                'lines' => $view->lines,
                'count' => $view->count(),
                'subtotal' => $view->subtotal(),
                'discount' => $applied?->discount() ?? '0.00',
                'coupon' => $applied ? $applied->coupon->code : null,
            ],
            'deliveryOptions' => $this->deliveryOptions($store),
            // Dónde y cuándo se recoge en ESTA tienda (o el aviso de que se confirma por WhatsApp).
            'pickup' => $store->pickupInfo(),
            'customerName' => $customer->name,
            'phone' => $customer->phone,
            'reserveHours' => (int) config('shop.reserve_hours', 48),
            // Con el máximo de apartados sin pagar no se puede apartar otro: se avisa antes del botón.
            'reservationLimit' => $this->orders->reservationLimitMessage($store, $customer),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->currentStore();
        /** @var Customer $customer */
        $customer = $request->user('customer');

        // Solo recoger en tienda y entrega local: cualquier otro método (también el nacional) se rechaza.
        $data = $request->validate([
            'delivery_method' => ['required', Rule::in(array_keys($this->orders->deliveryOptions($store)))],
            'phone' => ['required', 'string', 'regex:/^[0-9+()\s-]{10,20}$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'delivery_method.required' => 'Elige cómo quieres recibir tu pedido.',
            'delivery_method.in' => 'Ese método de entrega no está disponible en esta tienda.',
            'phone.regex' => 'Escribe un teléfono válido, por ejemplo 755 123 4567.',
        ], ['phone' => 'teléfono de contacto', 'notes' => 'notas']);

        $method = DeliveryMethod::from($data['delivery_method']);
        $phone = trim($data['phone']);
        $local = $method === DeliveryMethod::Local ? $this->localDelivery($request) : null;

        $shippingAddress = $local === null ? null : [
            'name' => $local['name'],
            'phone' => $phone,
            'street' => $local['point'],
            'city' => self::LOCAL_CITY,
            'notes' => $local['references'],
        ];

        try {
            $order = $this->orders->place($store, $customer, $method, $shippingAddress, $phone, $data['notes'] ?? null, [
                'date' => $local['preferred_date'] ?? null,
                'window' => $local['preferred_window'] ?? null,
            ]);
        } catch (CartChangedException $exception) {
            return to_route('cart.show')->with('cart_notices', $exception->notices);
        } catch (ReservationLimitException $exception) {
            return to_route('checkout')->withErrors(['reservations' => $exception->getMessage()]);
        }

        return to_route('order.thanks', ['number' => $order->number]);
    }

    /**
     * Datos de la entrega local: quién recibe, el punto de entrega propuesto, referencias y, si
     * quiere, el día y el rango de hora que prefiere. La fecha definitiva la confirma la tienda.
     *
     * @return array{name: string, point: string, references: ?string, preferred_date: ?string, preferred_window: ?string}
     */
    private function localDelivery(Request $request): array
    {
        $validated = $request->validate([
            'local' => ['required', 'array'],
            'local.name' => ['required', 'string', 'max:255'],
            'local.point' => ['required', 'string', 'max:500'],
            'local.references' => ['nullable', 'string', 'max:1000'],
            'local.preferred_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'local.preferred_window' => ['nullable', 'string', 'max:255'],
        ], [
            'local.required' => 'Dinos quién recibe y el punto de entrega.',
            'local.point.required' => 'Escribe el punto de entrega o la dirección en Zihuatanejo.',
            'local.preferred_date.date_format' => 'Elige un día válido.',
            'local.preferred_date.after_or_equal' => 'El día preferido no puede ser anterior a hoy.',
        ], [
            'local.name' => 'nombre de quien recibe',
            'local.point' => 'punto de entrega',
            'local.references' => 'referencias',
            'local.preferred_date' => 'día preferido',
            'local.preferred_window' => 'horario preferido',
        ])['local'];

        $text = fn (string $key) => filled($validated[$key] ?? null) ? trim($validated[$key]) : null;

        return [
            'name' => trim($validated['name']),
            'point' => trim($validated['point']),
            'references' => $text('references'),
            'preferred_date' => $text('preferred_date'),
            'preferred_window' => $text('preferred_window'),
        ];
    }

    /**
     * Las dos opciones de entrega para la pantalla, con su etiqueta y costo. Siempre las mismas dos;
     * el costo de la entrega local sale de la tienda (sin costo = 0.00, que la pantalla pinta «Gratis»).
     */
    private function deliveryOptions(Store $store): array
    {
        $labels = [
            DeliveryMethod::Pickup->value => ['Recoger en tienda', 'Pasas por tu pedido a la tienda.'],
            DeliveryMethod::Local->value => ['Entrega local (Zihuatanejo)', 'Te lo llevamos al punto que nos digas en Zihuatanejo.'],
        ];

        $options = [];

        foreach ($this->orders->deliveryOptions($store) as $method => $cents) {
            $options[] = [
                'value' => $method,
                'label' => $labels[$method][0],
                'description' => $labels[$method][1],
                'cost' => CouponResult::money($cents),
            ];
        }

        return $options;
    }

    private function currentStore(): Store
    {
        return app(ResolveStore::CONTAINER_KEY);
    }
}
