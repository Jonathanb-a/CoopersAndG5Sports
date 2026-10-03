<?php

namespace App\Filament\Widgets;

use App\Enums\OrderChannel;
use App\Enums\SalesPeriod;
use App\Services\SalesReport;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class SalesByChannelChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Ventas web contra mostrador';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $store = Filament::getTenant();
        $period = SalesPeriod::fromFilters($this->pageFilters);
        $totals = $store ? app(SalesReport::class)->salesByChannel($store, ...$period->range()) : [];

        return [
            'datasets' => [
                [
                    'label' => 'Ventas',
                    'data' => array_values($totals),
                    'backgroundColor' => ['#3b82f6', '#6b7280'],
                ],
            ],
            'labels' => array_map(fn (string $channel) => OrderChannel::from($channel)->getLabel(), array_keys($totals)),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
