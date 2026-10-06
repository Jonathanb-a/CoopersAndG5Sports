<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\OrderCancellationService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cancela los apartados que no se pagaron a tiempo: regresa sus piezas y quedan cancelados por el
 * sistema, así que se pueden reactivar desde el panel. Corre cada 10 minutos (routes/console.php).
 */
class ExpireUnpaidOrders extends Command
{
    public const REASON = 'Venció el plazo de pago';

    protected $signature = 'orders:expire-unpaid {--dry-run : Solo lista los pedidos que cancelaría, sin tocar nada}';

    protected $description = 'Cancela los pedidos apartados cuyo plazo de pago ya venció y regresa su stock';

    public function handle(OrderCancellationService $cancellations): int
    {
        $orders = $this->expired()->with(['store', 'customer'])->orderBy('id')->get();

        if ($this->option('dry-run')) {
            return $this->list($orders);
        }

        $cancelled = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                // Cada pedido en su propia transacción: si uno falla se deshace solo ese.
                $done = DB::transaction(function () use ($order, $cancellations) {
                    // Con el pedido bloqueado se revisa otra vez: pudo pagarse, cancelarse o recibir más
                    // tiempo desde que se armó la lista (o lo tomó otra corrida del comando).
                    $locked = $this->expired()->lockForUpdate()->find($order->getKey());

                    if ($locked === null) {
                        return false;
                    }

                    $cancellations->cancel($locked, null, self::REASON, restock: true);

                    return true;
                });
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                Log::error("orders:expire-unpaid: no se pudo cancelar el pedido {$order->number}: {$exception->getMessage()}");
                $this->error("No se pudo cancelar el pedido {$order->number}: {$exception->getMessage()}");

                continue;
            }

            if ($done) {
                $cancelled++;
                $this->line("Cancelado: {$order->number}");
            }
        }

        $summary = "orders:expire-unpaid: {$cancelled} pedidos cancelados por vencer el plazo de pago".($failed > 0 ? ", {$failed} con error" : '').'.';

        Log::info($summary);
        $this->info($summary);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Apartados con la fecha límite de pago ya vencida, de todas las tiendas. */
    private function expired(): Builder
    {
        return Order::withoutGlobalScopes()
            ->where('status', OrderStatus::Reserved->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    private function list($orders): int
    {
        if ($orders->isEmpty()) {
            $this->info('No hay apartados vencidos.');

            return self::SUCCESS;
        }

        $this->table(
            ['Pedido', 'Tienda', 'Cliente', 'Total', 'Venció'],
            $orders->map(fn (Order $order) => [
                $order->number,
                $order->store?->name ?? '—',
                $order->customer?->name ?? '—',
                $order->total,
                $order->expires_at->format('d/m/Y H:i'),
            ])->all(),
        );

        $this->info("Se cancelarían {$orders->count()} pedidos. No se tocó nada (--dry-run).");

        return self::SUCCESS;
    }
}
