<?php

namespace Tests\Feature;

use App\Enums\CouponType;
use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\SizeType;
use App\Enums\StockLevel;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use Illuminate\Support\Number;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    public function test_app_is_configured_for_mexican_spanish()
    {
        $this->assertSame('es', config('app.locale'));
        $this->assertSame('es', config('app.fallback_locale'));
        $this->assertSame('es_MX', config('app.faker_locale'));
        $this->assertSame('America/Mexico_City', config('app.timezone'));
    }

    public function test_laravel_translations_are_in_spanish()
    {
        $this->assertSame('El campo nombre es obligatorio.', __('validation.required', ['attribute' => 'nombre']));
        $this->assertSame('Estas credenciales no coinciden con nuestros registros.', __('auth.failed'));
    }

    public function test_prices_use_mexican_pesos_format()
    {
        $this->assertSame('$1,700.00', Number::currency(1700));
    }

    public function test_every_enum_has_a_spanish_label()
    {
        $expected = [
            UserRole::class => ['Dueño', 'Empleado'],
            OrderStatus::class => ['Apartado', 'Pagado', 'Enviado', 'Entregado', 'Cancelado'],
            OrderChannel::class => ['Web', 'Mostrador'],
            DeliveryMethod::class => ['Recoger en tienda', 'Entrega local', 'Envío nacional'],
            CouponType::class => ['Porcentaje', 'Monto fijo'],
            SizeType::class => ['Calzado', 'Ropa', 'Niños', 'Talla única'],
            StockMovementType::class => ['Entrada', 'Venta web', 'Venta en mostrador', 'Ajuste', 'Cancelación'],
            StockLevel::class => ['Agotado', 'Poco stock', 'Disponible'],
        ];

        foreach ($expected as $enum => $labels) {
            $this->assertSame($labels, array_map(fn ($case) => $case->getLabel(), $enum::cases()), $enum);
        }
    }
}
