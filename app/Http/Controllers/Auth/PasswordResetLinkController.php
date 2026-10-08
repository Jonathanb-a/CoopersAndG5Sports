<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Pide el enlace para recuperar la contraseña de un cliente (broker "customers"). */
class PasswordResetLinkController extends Controller
{
    public const STATUS = 'Si el correo existe, te enviamos un enlace para restablecer tu contraseña.';

    public function create(Request $request): Response
    {
        return Inertia::render('auth/forgot-password', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']], [], ['email' => 'correo']);

        try {
            Password::broker('customers')->sendResetLink($request->only('email'));
        } catch (Throwable $exception) {
            // Si el correo no sale, se registra y la pantalla responde igual: no hay que revelar nada ni tronar.
            Log::error("No se pudo mandar el enlace para restablecer una contraseña de cliente: {$exception->getMessage()}");
        }

        // Misma respuesta exista o no la cuenta (también con la cuenta de Google, que no tiene contraseña).
        return back()->with('status', self::STATUS);
    }
}
