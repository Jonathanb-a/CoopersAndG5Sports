<?php

namespace App\Filament\Auth;

use App\Support\PanelPasswordLink;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * "¿Olvidaste tu contraseña?" del panel. Responde siempre lo mismo (exista o no el correo, tenga o no
 * acceso al panel) y limita los intentos: 5 por minuto por correo+IP y 30 por hora por IP.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        $email = Str::lower(trim((string) ($this->data['email'] ?? '')));
        $ip = (string) request()->ip();

        $perEmail = 'panel-reset-link:'.sha1("{$email}|{$ip}");
        $perIp = 'panel-reset-link-ip:'.$ip;

        if (RateLimiter::tooManyAttempts($perEmail, 5) || RateLimiter::tooManyAttempts($perIp, 30)) {
            Notification::make()->title(PanelPasswordLink::THROTTLED)->danger()->send();

            return;
        }

        RateLimiter::hit($perEmail, 60);
        RateLimiter::hit($perIp, 3600);

        $data = $this->form->getState();

        try {
            PanelPasswordLink::send($this->getCredentialsFromFormData($data));
        } catch (Throwable $exception) {
            // Si el correo no sale se registra y la pantalla responde igual: no hay que revelar nada ni tronar.
            Log::error("No se pudo mandar el enlace para restablecer una contraseña del panel: {$exception->getMessage()}");
        }

        Notification::make()->title(PanelPasswordLink::SENT)->success()->send();

        $this->form->fill();
    }
}
