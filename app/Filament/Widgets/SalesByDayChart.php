<?php

namespace App\Filament\Widgets;

use App\Enums\SalesPeriod;
use App\Services\SalesReport;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class SalesByDayChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Ventas por día';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $store = Filament::getTenant();
        $period = SalesPeriod::fromFilters($this->pageFilters);
        $days = $store ? app(SalesReport::class)->salesByDay($store, ...$period->range()) : [];

        return [
            'datasets' => [
                [
                    'label' => 'Ventas',
                    'data' => array_values($days),
                    'tension' => 0.3,
                    'fill' => true,
                ],
            ],
            'labels' => array_map(fn (string $day) => Carbon::parse($day)->format('d/m'), array_keys($days)),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
