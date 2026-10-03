<?php

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use App\Services\InventoryReport;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class InventoryStats extends StatsOverviewWidget
{
    protected static ?int $sort = 8;

    protected function getStats(): array
    {
        $store = Filament::getTenant();

        if ($store === null) {
            return [];
        }

        $report = app(InventoryReport::class)->summary($store);

        $stats = [
            Stat::make('Productos activos', Number::format($report['active_products'])),
            Stat::make('Piezas en existencia', Number::format($report['pieces'])),
            Stat::make('Tallas agotadas', Number::format($report['sold_out_sizes']))
                ->description('Tallas sin piezas')
                ->color('danger'),
            Stat::make('Tallas en última pieza', Number::format($report['last_piece_sizes']))
                ->description('Tallas con una sola pieza')
                ->color('warning'),
        ];

        if (auth()->user()?->role === UserRole::Owner) {
            $stats[] = Stat::make('Valor del inventario a precio de venta', Number::currency($report['retail_value']));
            $stats[] = Stat::make('Valor del inventario a costo', Number::currency($report['cost_value']));
        }

        return $stats;
    }
}
