<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/** Pide el enlace para recuperar la contraseña de un cliente (broker "customers"). */
class PasswordResetLinkController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']], [], ['email' => 'correo']);

        Password::broker('customers')->sendResetLink($request->only('email'));

        // Misma respuesta exista o no la cuenta, para no revelar qué correos están registrados.
        return back()->with('status', 'Si el correo tiene una cuenta, te enviamos un enlace para restablecer tu contraseña.');
    }
}
