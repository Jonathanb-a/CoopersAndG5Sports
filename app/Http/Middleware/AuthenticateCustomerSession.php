<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra las sesiones de un cliente cuando cambia su contraseña (por restablecerla o por cambiarla
 * en «Mi cuenta»). Al iniciar sesión se guarda una huella de la contraseña en la sesión; si deja de
 * coincidir, esa sesión se cierra. Solo se quita el inicio de sesión: el carrito se conserva.
 *
 * El AuthenticateSession de Laravel usa el guard por defecto (el del panel), no el de clientes.
 */
class AuthenticateCustomerSession
{
    public const KEY = 'customer_password_hash';

    /** Huella de la contraseña actual del cliente ("id:huella"); no guarda la contraseña ni su hash tal cual. */
    public static function fingerprint(Customer $customer): string
    {
        return $customer->getKey().':'.hash_hmac('sha256', (string) $customer->getAuthPassword(), (string) config('app.key'));
    }

    /** Anota en la sesión actual la contraseña con la que el cliente está dentro. */
    public static function remember(Customer $customer): void
    {
        if (request()->hasSession()) {
            request()->session()->put(self::KEY, self::fingerprint($customer));
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('customer');

        if ($request->hasSession() && ($customer = $guard->user())) {
            $stored = $request->session()->get(self::KEY);

            // Una sesión sin huella, o que pasó a ser de otro cliente, solo anota la suya: no es un cambio de contraseña.
            if ($stored === null || ! str_starts_with((string) $stored, $customer->getKey().':')) {
                self::remember($customer);
            } elseif (! hash_equals($stored, self::fingerprint($customer))) {
                $guard->logoutCurrentDevice();
                $request->session()->forget(self::KEY);
                $request->session()->regenerate();
            }
        }

        return $next($request);
    }
}
