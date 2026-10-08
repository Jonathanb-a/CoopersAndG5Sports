<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateCustomerSession;
use App\Models\Customer;
use App\Models\Order;
use App\Observers\OrderObserver;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        Order::observe(OrderObserver::class);

        // Al iniciar sesión un cliente se anota la contraseña con la que entró, para poder cerrar sus
        // otras sesiones si la cambia o la restablece.
        Event::listen(Login::class, function (Login $event) {
            if ($event->guard === 'customer' && $event->user instanceof Customer) {
                AuthenticateCustomerSession::remember($event->user);
            }
        });

        // Recuperar contraseña de clientes: pocos intentos por correo y por dirección IP.
        $tooMany = fn (Request $request, array $headers) => back()->withErrors([
            'email' => 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.',
        ]);

        RateLimiter::for('customer-password-link', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip())->response($tooMany),
            Limit::perHour(30)->by($request->ip())->response($tooMany),
        ]);

        RateLimiter::for('customer-password-reset', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip())->response($tooMany),
        ]);

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
