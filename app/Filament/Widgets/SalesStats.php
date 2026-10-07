<?php

namespace App\Filament\Widgets;

use App\Enums\SalesPeriod;
use App\Enums\UserRole;
use App\Services\SalesReport;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Tarjetas de ventas del periodo, cada una comparada con el periodo anterior. Una venta es un pedido
 * pagado, enviado o entregado; los apartados van aparte como «por cobrar» y los cancelados no cuentan.
 */
class SalesStats extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $store = Filament::getTenant();

        if ($store === null) {
            return [];
        }

        $period = SalesPeriod::fromFilters($this->pageFilters);
        $report = app(SalesReport::class);
        $summary = $report->summary($store, ...$period->range());
        $previous = $report->summary($store, ...$period->previousRange());
        $receivable = $report->receivable($store);

        $stats = [
            $this->compared(Stat::make("Ventas · {$period->getLabel()}", Number::currency($summary['sales'])), $summary['sales'], $previous['sales']),
            $this->compared(Stat::make('Pedidos', Number::format($summary['orders'])), $summary['orders'], $previous['orders']),
            $this->compared(Stat::make('Ticket promedio', Number::currency($summary['average'])), $summary['average'], $previous['average']),
            // Es una foto de hoy, no del periodo: no hay un «antes» contra el cual compararla.
            Stat::make('Por cobrar', Number::currency($receivable['amount']))
                ->description($receivable['orders'] === 1 ? '1 pedido por cobrar, al día de hoy' : "{$receivable['orders']} pedidos por cobrar, al día de hoy")
                ->color('warning'),
        ];

        // La utilidad sale del costo de los productos: solo la ve el dueño.
        if (auth()->user()?->role === UserRole::Owner) {
            $stats[] = $this->compared(
                Stat::make('Utilidad estimada', Number::currency($summary['profit'])),
                $summary['profit'],
                $previous['profit'],
            );
        }

        return $stats;
    }

    /** Agrega la flecha y el porcentaje contra el periodo anterior. */
    private function compared(Stat $stat, float|int $current, float|int $previous): Stat
    {
        $change = SalesReport::change((float) $current, (float) $previous);

        return match (true) {
            $change === null && (float) $current == 0.0 => $stat
                ->description('Sin movimiento, igual que el periodo anterior')
                ->color('gray'),
            $change === null => $stat
                ->description('El periodo anterior fue 0')
                ->descriptionIcon(Heroicon::ArrowTrendingUp)
                ->color('success'),
            $change > 0 => $stat
                ->description(self::percent($change).' más que el periodo anterior')
                ->descriptionIcon(Heroicon::ArrowTrendingUp)
                ->color('success'),
            $change < 0 => $stat
                ->description(self::percent(abs($change)).' menos que el periodo anterior')
                ->descriptionIcon(Heroicon::ArrowTrendingDown)
                ->color('danger'),
            default => $stat
                ->description('Igual que el periodo anterior')
                ->color('gray'),
        };
    }

    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ','), '0'), '.').'%';
    }
}
