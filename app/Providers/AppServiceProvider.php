<?php

namespace App\Providers;

use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Pesos mexicanos ($1,700.00) en los helpers de Laravel.
        Number::useLocale('es_MX');
        Number::useCurrency('MXN');

        // Formato de pesos y fechas día/mes/año en todo el panel de Filament.
        Table::configureUsing(fn (Table $table): Table => $table
            ->defaultCurrency('MXN')
            ->defaultNumberLocale('es_MX')
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i')
            ->defaultTimeDisplayFormat('H:i'));

        Schema::configureUsing(fn (Schema $schema): Schema => $schema
            ->defaultCurrency('MXN')
            ->defaultNumberLocale('es_MX')
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i')
            ->defaultTimeDisplayFormat('H:i'));
    }
}
