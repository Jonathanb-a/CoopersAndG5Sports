<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * Inicio de sesión de clientes con Google. Si el correo ya tiene cuenta, se vincula el google_id;
 * si no, se crea el Customer sin contraseña. La URL de retorno usa el dominio de la tienda actual.
 */
class GoogleAuthController extends Controller
{
    /** Sin credenciales en .env, la ruta no existe y el botón no se muestra. */
    public static function enabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function redirect(): RedirectResponse
    {
        abort_unless(self::enabled(), 404);

        return Socialite::driver('google')->redirectUrl($this->callbackUrl())->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(self::enabled(), 404);

        // El cliente canceló en la pantalla de Google.
        if ($request->has('error')) {
            return $this->failed('Cancelaste el inicio de sesión con Google.');
        }

        try {
            $google = Socialite::driver('google')->redirectUrl($this->callbackUrl())->user();
        } catch (InvalidStateException|ClientException) {
            return $this->failed('No pudimos iniciar sesión con Google. Inténtalo de nuevo.');
        }

        $email = Str::lower((string) $google->getEmail());

        // Solo se confía en un correo que Google marca como verificado: si no, alguien podría
        // quedarse con la cuenta de otra persona registrando su correo en Google sin confirmarlo.
        if ($email === '' || ! filter_var($google->user['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return $this->failed('Tu cuenta de Google no tiene un correo verificado.');
        }

        $customer = Customer::where('google_id', $google->getId())->first();

        if (! $customer) {
            $customer = Customer::where('email', $email)->first();

            if ($customer && filled($customer->google_id)) {
                return $this->failed('Ese correo ya está vinculado a otra cuenta de Google.');
            }

            if ($customer) {
                $customer->forceFill(['google_id' => $google->getId()])->save();
            } else {
                $customer = Customer::create([
                    'name' => $google->getName() ?: Str::before($email, '@'),
                    'email' => $email,
                    'google_id' => $google->getId(),
                ]);
            }
        }

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    private function callbackUrl(): string
    {
        return url('/auth/google/callback');
    }

    private function failed(string $message): RedirectResponse
    {
        return to_route('login')->withErrors(['email' => $message]);
    }
}
