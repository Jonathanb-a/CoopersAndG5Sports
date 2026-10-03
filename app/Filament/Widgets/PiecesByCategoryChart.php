<?php

namespace App\Filament\Widgets;

use App\Services\InventoryReport;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

class PiecesByCategoryChart extends ChartWidget
{
    protected static ?int $sort = 9;

    protected ?string $heading = 'Piezas por categoría';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $store = Filament::getTenant();
        $pieces = $store ? app(InventoryReport::class)->piecesByCategory($store) : [];

        return [
            'datasets' => [
                [
                    'label' => 'Piezas',
                    'data' => array_values($pieces),
                ],
            ],
            'labels' => array_keys($pieces),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['ticks' => ['precision' => 0]]],
        ];
    }
}
