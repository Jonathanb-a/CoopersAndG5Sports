<?php

namespace App\Filament\Auth;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Login del panel con límite propio contra adivinar contraseñas, además del de Filament (5 por minuto por IP):
 * 5 fallos por minuto por correo+IP y 30 fallos por hora por IP. Solo cuentan los intentos fallidos.
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $email = Str::lower(trim((string) ($this->data['email'] ?? '')));
        $ip = (string) request()->ip();

        $perEmail = 'panel-login:'.sha1("{$email}|{$ip}");
        $perIp = 'panel-login-ip:'.$ip;

        if (RateLimiter::tooManyAttempts($perEmail, 5) || RateLimiter::tooManyAttempts($perIp, 30)) {
            Notification::make()->title('Demasiados intentos. Espera unos minutos e inténtalo de nuevo.')->danger()->send();

            return null;
        }

        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            RateLimiter::hit($perEmail, 60);
            RateLimiter::hit($perIp, 3600);

            throw $exception;
        }

        if ($response !== null) {
            RateLimiter::clear($perEmail);
        }

        return $response;
    }
}
