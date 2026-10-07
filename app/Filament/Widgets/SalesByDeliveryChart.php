<?php

namespace App\Filament\Widgets;

use App\Enums\DeliveryMethod;
use App\Enums\SalesPeriod;
use App\Services\SalesReport;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** Dona: ventas del periodo por método de entrega (recoger, local, nacional). */
class SalesByDeliveryChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Ventas por método de entrega';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $store = Filament::getTenant();
        $period = SalesPeriod::fromFilters($this->pageFilters);
        $totals = $store ? app(SalesReport::class)->salesByDelivery($store, ...$period->range()) : [];

        return [
            'datasets' => [
                [
                    'label' => 'Ventas',
                    'data' => array_values($totals),
                    'backgroundColor' => ['#f59e0b', '#10b981', '#6366f1'],
                ],
            ],
            'labels' => array_map(fn (string $method) => DeliveryMethod::from($method)->getLabel(), array_keys($totals)),
        ];
    }
}
