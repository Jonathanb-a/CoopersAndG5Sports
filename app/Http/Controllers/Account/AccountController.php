<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateCustomerSession;
use App\Models\Address;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/** «Mi cuenta»: datos del cliente, contraseña y libreta de direcciones. */
class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        $customer = $request->user('customer');

        return Inertia::render('account', [
            'customer' => [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'has_password' => $customer->hasPassword(),
                'has_google' => filled($customer->google_id),
            ],
            'addresses' => $customer->addresses()->orderBy('id')->get()
                ->map(fn (Address $address) => $address->only(['id', 'street', 'neighborhood', 'city', 'state', 'zip', 'notes']))
                ->all(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^[0-9+()\s-]{10,20}$/'],
        ], [
            'phone.regex' => 'Escribe un teléfono válido, por ejemplo 755 123 4567.',
        ], ['name' => 'nombre', 'phone' => 'teléfono']);

        $request->user('customer')->update([
            'name' => $data['name'],
            'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
        ]);

        return back()->with('status', 'Datos guardados.');
    }

    /** Cambia la contraseña; quien entró con Google y no tiene una puede crearla sin la actual. */
    public function updatePassword(Request $request): RedirectResponse
    {
        $customer = $request->user('customer');

        $data = $request->validate([
            'current_password' => $customer->hasPassword() ? ['required', 'string', 'current_password:customer'] : ['nullable'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], [], ['current_password' => 'contraseña actual', 'password' => 'contraseña nueva']);

        $customer->update(['password' => $data['password']]);

        // Las demás sesiones de este cliente se cierran; esta sigue con la contraseña nueva.
        AuthenticateCustomerSession::remember($customer);

        return back()->with('status', 'Contraseña actualizada.');
    }
}
