<?php

namespace App\Notifications;

use App\Http\Middleware\ResolveStore;
use App\Support\StoreMailTheme;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Correo para restablecer la contraseña de un cliente. Sale en cola, así que la tienda desde la que
 * se pidió (dirección, nombre y tema) se fija al crear la notificación: un trabajo en cola ya no tiene
 * la petición del cliente para saber de qué dominio venía.
 */
class CustomerResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /** Intentos del trabajo en cola si el servidor de correo falla. */
    public int $tries = 3;

    /** @var list<int> segundos entre intentos */
    public array $backoff = [30, 120];

    /**
     * @param  string  $baseUrl  esquema y dominio de la tienda, por ejemplo "http://g5.localhost"
     * @param  string  $theme  tema de la tienda ("coppers" o "g5")
     */
    public function __construct($token, public string $baseUrl, public string $storeName, public string $theme)
    {
        parent::__construct($token);
    }

    /** La notificación de la tienda desde la que viene la petición actual. */
    public static function forCurrentStore(string $token): self
    {
        $store = app()->bound(ResolveStore::CONTAINER_KEY) ? app(ResolveStore::CONTAINER_KEY) : null;

        return new self($token, request()->getSchemeAndHttpHost(), $store?->name ?? config('app.name'), (string) $store?->theme);
    }

    /** El enlace abre el dominio de la tienda que lo pidió, no el APP_URL. */
    protected function resetUrl($notifiable): string
    {
        return rtrim($this->baseUrl, '/').route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false);
    }

    public function toMail($notifiable): MailMessage
    {
        $data = [
            'store' => $this->storeName,
            'url' => $this->resetUrl($notifiable),
            'minutes' => (int) config('auth.passwords.customers.expire'),
            'theme' => StoreMailTheme::for($this->theme),
            'logo' => StoreMailTheme::logoUrl($this->theme, $this->baseUrl),
            // Quien entró con Google no tiene contraseña: el mismo enlace sirve para crear una.
            'creatingPassword' => method_exists($notifiable, 'hasPassword') && ! $notifiable->hasPassword(),
        ];

        return (new MailMessage)
            ->from(config('mail.from.address'), $this->storeName)
            ->subject("Restablece tu contraseña de {$this->storeName}")
            ->view(['mail.customer-reset-password', 'mail.customer-reset-password-text'], $data);
    }

    /** Si el correo no se pudo mandar después de los intentos, queda en el registro (sin el enlace). */
    public function failed(Throwable $exception): void
    {
        Log::error("No se pudo mandar el correo para restablecer la contraseña de {$this->storeName}: {$exception->getMessage()}");
    }
}
