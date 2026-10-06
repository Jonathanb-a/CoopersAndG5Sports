<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMethod;
use App\Http\Middleware\ResolveStore;
use App\Models\Address;
use App\Models\Customer;
use App\Models\Store;
use App\Services\CartChangedException;
use App\Services\CartService;
use App\Services\CouponResult;
use App\Services\CouponService;
use App\Services\OrderService;
use App\Support\AddressRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checkout: resumen, entrega, contacto y «Apartar pedido». Exige cliente con sesión.
 * Del navegador solo se aceptan el método de entrega, la dirección, el teléfono y las notas:
 * líneas, precios, descuento y envío los calcula el servidor.
 */
class CheckoutController extends Controller
{
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
            'addresses' => $customer->addresses()->orderBy('id')->get()
                ->map(fn (Address $address) => $address->only(['id', ...AddressRules::FIELDS]))
                ->all(),
            'phone' => $customer->phone,
            'reserveHours' => (int) config('shop.reserve_hours', 48),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->currentStore();
        /** @var Customer $customer */
        $customer = $request->user('customer');

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

        $shippingAddress = $method === DeliveryMethod::Pickup
            ? null
            : [...$this->address($request, $customer), 'name' => $customer->name, 'phone' => $phone];

        try {
            $order = $this->orders->place($store, $customer, $method, $shippingAddress, $phone, $data['notes'] ?? null);
        } catch (CartChangedException $exception) {
            return to_route('cart.show')->with('cart_notices', $exception->notices);
        }

        return to_route('order.thanks', ['number' => $order->number]);
    }

    /**
     * Dirección de entrega: una guardada del propio cliente (address_id) o una nueva, que además se
     * guarda en su libreta.
     *
     * @return array<string, mixed>
     */
    private function address(Request $request, Customer $customer): array
    {
        if ($request->filled('address_id')) {
            $request->validate([
                'address_id' => ['integer', Rule::exists('addresses', 'id')->where('customer_id', $customer->id)],
            ], ['address_id.*' => 'Elige una de tus direcciones o captura una nueva.']);

            $address = $customer->addresses()->findOrFail((int) $request->input('address_id'));

            return $address->only(AddressRules::FIELDS);
        }

        $validated = $request->validate(
            ['address' => ['required', 'array'], ...AddressRules::rules('address.')],
            ['address.required' => 'Elige una dirección o captura una nueva.', ...AddressRules::messages('address.')],
            AddressRules::attributes('address.'),
        );

        $address = AddressRules::clean($validated['address']);
        $customer->addresses()->create($address);

        return $address;
    }

    /** Opciones de entrega para la pantalla, con su etiqueta y costo. */
    private function deliveryOptions(Store $store): array
    {
        $labels = [
            DeliveryMethod::Pickup->value => ['Recoger en tienda', 'Pasas por tu pedido a la tienda.'],
            DeliveryMethod::Local->value => ['Entrega local en Zihuatanejo', 'Te lo llevamos a tu domicilio en Zihuatanejo.'],
            DeliveryMethod::National->value => ['Envío nacional', 'Te lo mandamos por paquetería a cualquier parte de México.'],
        ];

        $options = [];

        foreach ($this->orders->deliveryOptions($store) as $method => $cents) {
            $options[] = [
                'value' => $method,
                'label' => $labels[$method][0],
                'description' => $labels[$method][1],
                'cost' => CouponResult::money($cents),
                'needs_address' => $method !== DeliveryMethod::Pickup->value,
            ];
        }

        return $options;
    }

    private function currentStore(): Store
    {
        return app(ResolveStore::CONTAINER_KEY);
    }
}
