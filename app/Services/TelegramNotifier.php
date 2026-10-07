<?php

namespace App\Services;

use App\Jobs\SendTelegramMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos al dueño por un bot de Telegram (API sendMessage). Un aviso nunca debe romper lo que lo
 * originó: sin configuración no manda nada, y si Telegram falla solo queda en el log.
 */
class TelegramNotifier
{
    private const TIMEOUT_SECONDS = 5;

    /**
     * Encola el aviso para mandarlo fuera de la petición del cliente, y solo cuando la transacción
     * en curso (si hay) ya se confirmó: un pedido que se deshace no avisa.
     */
    public function queue(string $message): void
    {
        if (! $this->configured()) {
            Log::info('Telegram sin configurar (TELEGRAM_BOT_TOKEN / TELEGRAM_OWNER_CHAT_ID): no se mandó el aviso.');

            return;
        }

        try {
            SendTelegramMessage::dispatch($message)->afterCommit();
        } catch (Throwable $exception) {
            Log::error('No se pudo encolar el aviso de Telegram: '.$this->clean($exception->getMessage()));
        }
    }

    /** Manda el mensaje al chat del dueño. Devuelve si Telegram lo aceptó; nunca lanza. */
    public function send(string $message): bool
    {
        if (! $this->configured()) {
            Log::info('Telegram sin configurar (TELEGRAM_BOT_TOKEN / TELEGRAM_OWNER_CHAT_ID): no se mandó el aviso.');

            return false;
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post("https://api.telegram.org/bot{$this->token()}/sendMessage", [
                    'chat_id' => $this->chatId(),
                    'text' => $message,
                ]);

            if ($response->failed()) {
                Log::error("Telegram rechazó el aviso (HTTP {$response->status()}): ".$this->clean($response->body()));

                return false;
            }

            return true;
        } catch (Throwable $exception) {
            Log::error('No se pudo mandar el aviso de Telegram: '.$this->clean($exception->getMessage()));

            return false;
        }
    }

    public function configured(): bool
    {
        return $this->token() !== '' && $this->chatId() !== '';
    }

    private function token(): string
    {
        return trim((string) config('services.telegram.bot_token'));
    }

    private function chatId(): string
    {
        return trim((string) config('services.telegram.owner_chat_id'));
    }

    /** El token va en la URL de Telegram: se quita de cualquier texto antes de mandarlo al log. */
    private function clean(string $text): string
    {
        return $this->token() === '' ? $text : str_replace($this->token(), '[token]', $text);
    }
}
