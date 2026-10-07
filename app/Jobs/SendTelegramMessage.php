<?php

namespace App\Jobs;

use App\Services\TelegramNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Manda un aviso al dueño por Telegram, fuera de la petición del cliente. Con la cola sync corre en el momento. */
class SendTelegramMessage implements ShouldQueue
{
    use Queueable;

    /** Un aviso que falla no se reintenta: TelegramNotifier ya lo dejó en el log. */
    public int $tries = 1;

    public function __construct(public readonly string $message) {}

    public function handle(TelegramNotifier $telegram): void
    {
        $telegram->send($this->message);
    }
}
