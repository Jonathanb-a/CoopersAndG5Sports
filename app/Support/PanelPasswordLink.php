<?php

namespace App\Support;

use App\Notifications\PanelResetPassword;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

/**
 * Manda a un usuario del panel el enlace para definir su contraseña (broker "users", tabla
 * password_reset_tokens: la de clientes es otra). Lo usan la pantalla "¿Olvidaste tu contraseña?"
 * y la acción del owner en Usuarios.
 */
class PanelPasswordLink
{
    /** Lo único que ve quien lo pide: igual exista o no el correo (y tenga o no acceso al panel). */
    public const SENT = 'Si el correo existe, te enviamos un enlace para restablecer tu contraseña.';

    public const THROTTLED = 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.';

    /**
     * Devuelve el estado del broker (Password::RESET_LINK_SENT, INVALID_USER, RESET_THROTTLED). Quien
     * muestre algo a un desconocido no debe distinguirlos; el owner sí los usa.
     *
     * @param  array<string, mixed>  $credentials
     */
    public static function send(#[SensitiveParameter] array $credentials): string
    {
        return Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            $credentials,
            function (CanResetPassword $user, #[SensitiveParameter] string $token): void {
                if ($user instanceof FilamentUser && ! $user->canAccessPanel(Filament::getCurrentOrDefaultPanel())) {
                    return;
                }

                $user->notify(PanelResetPassword::forUser($token, $user));
            },
        );
    }
}
