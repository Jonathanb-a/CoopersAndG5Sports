<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Base de las pruebas de cuentas de clientes: dos tiendas y direcciones con dominio. */
abstract class CustomerAuthTestCase extends TestCase
{
    use RefreshDatabase;

    protected const COPPERS = 'http://coppers.localhost';

    protected const G5 = 'http://g5.localhost';

    protected Store $coppers;

    protected Store $g5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);
    }

    protected function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create($attributes);
    }
}
