<?php

namespace App\Filament\Pages;

use App\Enums\SalesPeriod;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label('Periodo de ventas')
                ->options(SalesPeriod::class)
                ->default(SalesPeriod::DEFAULT->value)
                ->native(false)
                ->selectablePlaceholder(false),
        ]);
    }
}
