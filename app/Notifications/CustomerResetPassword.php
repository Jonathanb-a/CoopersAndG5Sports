<?php

namespace App\Notifications;

use App\Http\Middleware\ResolveStore;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Correo para restablecer la contraseña de un cliente, con el nombre y el dominio de la tienda
 * desde la que lo pidió (el enlace usa el host de la petición actual).
 */
class CustomerResetPassword extends ResetPassword
{
    protected function buildMailMessage($url): MailMessage
    {
        $store = app()->bound(ResolveStore::CONTAINER_KEY) ? app(ResolveStore::CONTAINER_KEY)->name : config('app.name');
        $minutes = config('auth.passwords.customers.expire');

        return (new MailMessage)
            ->from(config('mail.from.address'), $store)
            ->subject("Restablece tu contraseña de {$store}")
            ->greeting('¡Hola!')
            ->line("Recibimos una solicitud para restablecer la contraseña de tu cuenta en {$store}.")
            ->action('Restablecer contraseña', $url)
            ->line("Este enlace vence en {$minutes} minutos.")
            ->line('Si no lo pediste, ignora este correo: tu contraseña no cambiará.')
            ->salutation("Saludos, {$store}");
    }
}
