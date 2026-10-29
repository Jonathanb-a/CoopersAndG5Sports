<?php

namespace App\Notifications;

use App\Http\Middleware\ResolveStore;
use App\Models\User;
use App\Support\StoreMailTheme;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Correo para restablecer la contraseña de un usuario del panel (personal). Sale en cola, así que el
 * enlace y la marca se fijan al crearla: el trabajo ya no tiene la petición que dice desde qué dominio
 * (y por tanto desde qué tienda) se pidió. El enlace abre ese mismo dominio, nunca otro.
 */
class PanelResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /** Intentos del trabajo en cola si el servidor de correo falla. */
    public int $tries = 3;

    /** @var list<int> segundos entre intentos */
    public array $backoff = [30, 120];

    /**
     * @param  string  $url  enlace completo, con el dominio desde el que se pidió
     * @param  string|null  $theme  tema de la tienda de ese dominio, o null si el dominio no es de una tienda
     */
    public function __construct($token, public string $url, public string $storeName, public ?string $theme)
    {
        parent::__construct($token);
    }

    /** La notificación para la petición actual: mismo esquema y dominio que el panel desde el que se pidió. */
    public static function forUser(string $token, User $user): self
    {
        $filamentUrl = Filament::getResetPasswordUrl($token, $user);
        $query = parse_url($filamentUrl, PHP_URL_QUERY);
        $url = request()->getSchemeAndHttpHost().parse_url($filamentUrl, PHP_URL_PATH).($query ? '?'.$query : '');

        $store = ResolveStore::forHost(request()->getHost());

        return new self($token, $url, $store?->name ?? config('app.name'), $store?->theme);
    }

    protected function resetUrl($notifiable): string
    {
        return $this->url;
    }

    public function toMail($notifiable): MailMessage
    {
        $origin = parse_url($this->url, PHP_URL_SCHEME).'://'.parse_url($this->url, PHP_URL_HOST).(($port = parse_url($this->url, PHP_URL_PORT)) ? ":{$port}" : '');

        $data = [
            'store' => $this->storeName,
            'url' => $this->url,
            'minutes' => (int) config('auth.passwords.users.expire'),
            'theme' => StoreMailTheme::for($this->theme),
            'logo' => StoreMailTheme::logoUrl($this->theme, $origin),
        ];

        return (new MailMessage)
            ->from(config('mail.from.address'), $this->storeName)
            ->subject("Restablece tu contraseña del panel de {$this->storeName}")
            ->view(['mail.panel-reset-password', 'mail.panel-reset-password-text'], $data);
    }

    /** Si el correo no se pudo mandar después de los intentos, queda en el registro (sin el enlace). */
    public function failed(Throwable $exception): void
    {
        Log::error("No se pudo mandar el correo para restablecer la contraseña del panel de {$this->storeName}: {$exception->getMessage()}");
    }
}
