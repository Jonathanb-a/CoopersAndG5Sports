<?php

namespace App\Filament\Auth;

use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Pantalla donde el personal escribe su contraseña nueva con el enlace del correo.
 *
 * Distinta a la de Filament en dos cosas: un correo que no existe da el MISMO error que un token malo
 * (la de Filament dice "no encontramos un usuario", y con un enlace armado a mano eso revelaría qué
 * correos existen), y los intentos se limitan por IP y por correo.
 */
class ResetPassword extends BaseResetPassword
{
    public function resetPassword(): ?PasswordResetResponse
    {
        $ip = (string) request()->ip();
        $perIp = 'panel-reset-save:'.$ip;
        $perEmail = 'panel-reset-save-email:'.sha1(Str::lower((string) $this->email));

        if (RateLimiter::tooManyAttempts($perIp, 10) || RateLimiter::tooManyAttempts($perEmail, 5)) {
            Notification::make()->title('Demasiados intentos. Espera unos minutos e inténtalo de nuevo.')->danger()->send();

            return null;
        }

        RateLimiter::hit($perIp, 60);
        RateLimiter::hit($perEmail, 60);

        $data = $this->form->getState();

        $data['email'] = $this->email;
        $data['token'] = $this->token;

        $hasPanelAccess = true;

        $status = Password::broker(Filament::getAuthPasswordBroker())->reset(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword|Model|Authenticatable $user) use ($data, &$hasPanelAccess): void {
                if ($user instanceof FilamentUser && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
                    $hasPanelAccess = false;

                    return;
                }

                // La contraseña nueva cambia la huella de la sesión (AuthenticateSession del panel) y el token
                // de recordarme: las demás sesiones abiertas de esta persona dejan de valer.
                $user->forceFill([
                    $user->getAuthPasswordName() => Hash::make($data['password']),
                    $user->getRememberTokenName() => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($hasPanelAccess === false || $status === Password::INVALID_USER) {
            $status = Password::INVALID_TOKEN;
        }

        if ($status === Password::PASSWORD_RESET) {
            RateLimiter::clear($perEmail);

            Notification::make()->title(__($status))->success()->send();

            return app(PasswordResetResponse::class);
        }

        Notification::make()->title(__($status))->danger()->send();

        return null;
    }
}
