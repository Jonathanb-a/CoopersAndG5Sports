<?php

namespace App\Http\Controllers;

use App\Enums\CouponType;
use App\Http\Middleware\ResolveStore;
use App\Models\Store;
use App\Services\CartException;
use App\Services\CartService;
use App\Services\CartView;
use App\Services\CouponResult;
use App\Services\CouponService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Carrito de la tienda actual: /carrito. No pide cuenta; la cuenta se pide en /checkout.
 * Solo se aceptan ids y cantidades: el precio y todo lo demás se lee de la base de datos.
 */
class CartController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CouponService $coupons,
    ) {}

    public function show(Request $request): Response
    {
        $store = $this->currentStore();

        // Corrige el carrito (se agotó, cambió el precio...) y avisa de lo que cambió.
        $view = $this->cart->reconcile($store);
        // Avisos de esta carga, más los que mandó el checkout al regresar al carrito.
        $notices = [...(array) $request->session()->pull('cart_notices', []), ...$view->notices];

        // El cupón se revalida en cada carga: si ya no vale se quita y se avisa el motivo.
        [$applied, $couponNotice] = $this->revalidateCoupon($store, $view);
        $couponNotice ??= $request->session()->pull('coupon_notice');

        if ($couponNotice) {
            $notices[] = $couponNotice;
        }

        return Inertia::render('cart', [
            'cart' => [
                'lines' => $view->lines,
                'count' => $view->count(),
                'subtotal' => $view->subtotal(),
                'discount' => $applied?->discount() ?? '0.00',
                'total' => $applied?->total() ?? $view->subtotal(),
                'coupon' => $applied ? [
                    'code' => $applied->coupon->code,
                    'description' => $this->describe($applied),
                ] : null,
            ],
            'notices' => $notices,
            'status' => $request->session()->get('status'),
            'maxQuantity' => (int) config('shop.max_line_quantity', 10),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        try {
            $this->cart->add($this->currentStore(), (int) $data['variant_id'], (int) ($data['quantity'] ?? 1));
        } catch (CartException $exception) {
            return back()->withErrors(['cart' => $exception->getMessage()]);
        }

        return back()->with('status', 'Agregado a tu carrito.');
    }

    public function update(Request $request, int $variant): RedirectResponse
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:1000']]);

        try {
            $this->cart->setQuantity($this->currentStore(), $variant, (int) $data['quantity']);
        } catch (CartException $exception) {
            return to_route('cart.show')->withErrors(['cart' => $exception->getMessage()]);
        }

        return $this->backToCart();
    }

    public function destroy(int $variant): RedirectResponse
    {
        $this->cart->remove($this->currentStore(), $variant);

        return $this->backToCart();
    }

    public function clear(): RedirectResponse
    {
        $this->cart->clear($this->currentStore());

        return $this->backToCart();
    }

    public function applyCoupon(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50']], [
            'code.required' => 'Escribe un código de descuento.',
        ]);

        $store = $this->currentStore();
        $result = $this->coupons->apply($store, $data['code'], $this->cart->view($store)->subtotal());

        if (! $result->valid) {
            return to_route('cart.show')->withErrors(['code' => $result->reason]);
        }

        return to_route('cart.show')->with('status', "Cupón {$result->coupon->code} aplicado.");
    }

    public function removeCoupon(): RedirectResponse
    {
        $this->coupons->remove();

        return to_route('cart.show')->with('status', 'Quitaste el cupón.');
    }

    /** Después de cambiar una cantidad (o quitar o vaciar) se revalida el cupón y, si ya no vale, se avisa. */
    private function backToCart(): RedirectResponse
    {
        $store = $this->currentStore();
        [, $notice] = $this->revalidateCoupon($store, $this->cart->view($store));

        $redirect = to_route('cart.show');

        return $notice ? $redirect->with('coupon_notice', $notice) : $redirect;
    }

    /**
     * Revisa el cupón aplicado con el subtotal de ahora. Devuelve [cupón válido o null, aviso o null].
     * Si dejó de valer lo quita de la sesión y el aviso trae el motivo. Con el carrito vacío el cupón
     * se quita sin avisar.
     *
     * @return array{0: ?CouponResult, 1: ?string}
     */
    private function revalidateCoupon(Store $store, CartView $view): array
    {
        if ($view->isEmpty()) {
            $this->coupons->remove();

            return [null, null];
        }

        $code = session(CouponService::SESSION_KEY.'.code');
        $result = $this->coupons->applied($store, $view->subtotal());

        if ($result === null) {
            return [null, null];
        }

        return $result->valid
            ? [$result, null]
            : [null, "Quitamos el cupón {$code}: {$result->reason}"];
    }

    private function describe(CouponResult $result): string
    {
        $coupon = $result->coupon;

        return $coupon->type === CouponType::Percent
            ? rtrim(rtrim(number_format((float) $coupon->value, 2), '0'), '.').' % de descuento'
            : Number::currency((float) $coupon->value).' de descuento';
    }

    private function currentStore(): Store
    {
        return app(ResolveStore::CONTAINER_KEY);
    }
}
