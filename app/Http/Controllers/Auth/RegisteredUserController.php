<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Registro de clientes con correo y contraseña. La cuenta es una sola para todas las tiendas. */
class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.Customer::class],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [
            'email.unique' => 'Este correo ya tiene una cuenta. Inicia sesión: es la misma cuenta en todas nuestras tiendas.',
        ], [
            'name' => 'nombre',
            'email' => 'correo',
            'password' => 'contraseña',
        ]);

        $customer = Customer::create($data);

        event(new Registered($customer));

        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();

        return redirect()->intended('/');
    }
}
