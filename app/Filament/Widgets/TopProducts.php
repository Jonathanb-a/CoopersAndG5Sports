<?php

namespace App\Filament\Widgets;

use App\Enums\SalesPeriod;
use App\Services\SalesReport;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** Barras: los 5 productos más vendidos del periodo, por piezas. */
class TopProducts extends ChartWidget
{
    use InteractsWithPageFilters;

    public const LIMIT = 5;

    protected static ?int $sort = 6;

    protected ?string $heading = 'Más vendidos';

    protected ?string $description = 'Los 5 productos con más piezas vendidas en el periodo.';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $store = Filament::getTenant();
        $period = SalesPeriod::fromFilters($this->pageFilters);
        $rows = $store ? app(SalesReport::class)->topProducts($store, ...[...$period->range(), self::LIMIT]) : [];

        return [
            'datasets' => [
                [
                    'label' => 'Piezas vendidas',
                    'data' => array_column($rows, 'pieces'),
                    'backgroundColor' => '#10b981',
                ],
            ],
            'labels' => array_column($rows, 'name'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['ticks' => ['precision' => 0]]],
        ];
    }
}
