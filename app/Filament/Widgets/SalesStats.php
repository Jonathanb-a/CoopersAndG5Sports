<?php

namespace App\Filament\Widgets;

use App\Enums\SalesPeriod;
use App\Enums\UserRole;
use App\Services\SalesReport;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

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

        $stats = [
            Stat::make('Ventas', Number::currency($summary['sales']))
                ->description("Pedidos pagados, enviados y entregados · {$period->getLabel()}")
                ->color('success'),
            Stat::make('Pedidos', Number::format($summary['orders']))
                ->description($period->getLabel()),
            Stat::make('Ticket promedio', Number::currency($summary['average']))
                ->description($period->getLabel()),
            Stat::make('Apartados pendientes de pago', Number::format($report->pendingReservations($store)))
                ->description('Al día de hoy, sin importar el periodo')
                ->color('warning'),
        ];

        if (auth()->user()?->role === UserRole::Owner) {
            $stats[] = Stat::make('Ganancia estimada', Number::currency($summary['profit']))
                ->description("Venta menos costo de lo vendido · {$period->getLabel()}")
                ->color('success');
        }

        return $stats;
    }
}
