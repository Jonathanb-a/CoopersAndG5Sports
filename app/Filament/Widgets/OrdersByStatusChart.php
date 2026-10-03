<?php

namespace App\Filament\Widgets;

use App\Enums\OrderStatus;
use App\Enums\SalesPeriod;
use App\Services\SalesReport;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class OrdersByStatusChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected ?string $heading = 'Pedidos por estado';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $store = Filament::getTenant();
        $period = SalesPeriod::fromFilters($this->pageFilters);
        $counts = $store ? app(SalesReport::class)->ordersByStatus($store, ...$period->range()) : [];

        $colors = [
            OrderStatus::Reserved->value => '#f59e0b',
            OrderStatus::Paid->value => '#10b981',
            OrderStatus::Shipped->value => '#3b82f6',
            OrderStatus::Delivered->value => '#6366f1',
            OrderStatus::Cancelled->value => '#ef4444',
        ];

        return [
            'datasets' => [
                [
                    'label' => 'Pedidos',
                    'data' => array_values($counts),
                    'backgroundColor' => array_map(fn (string $status) => $colors[$status], array_keys($counts)),
                ],
            ],
            'labels' => array_map(fn (string $status) => OrderStatus::from($status)->getLabel(), array_keys($counts)),
        ];
    }
}
