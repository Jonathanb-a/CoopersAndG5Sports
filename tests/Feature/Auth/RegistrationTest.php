<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

class RegistrationTest extends CustomerAuthTestCase
{
    private function data(array $extra = []): array
    {
        return [
            'name' => 'Ana Pérez',
            'email' => 'ana@example.com',
            'password' => 'password-segura',
            'password_confirmation' => 'password-segura',
            ...$extra,
        ];
    }

    public function test_registration_screen_can_be_rendered()
    {
        $this->get(self::COPPERS.'/register')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/register')->where('store.name', "Copper's"));
    }

    public function test_new_customers_can_register_and_start_logged_in()
    {
        $this->post(self::COPPERS.'/register', $this->data())->assertRedirect('/');

        $customer = Customer::firstWhere('email', 'ana@example.com');

        $this->assertNotNull($customer);
        $this->assertSame('Ana Pérez', $customer->name);
        $this->assertTrue(Hash::check('password-segura', $customer->password));
        $this->assertNull($customer->google_id);
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_the_email_must_be_new_and_the_password_confirmed()
    {
        $this->customer(['email' => 'ana@example.com']);

        $this->post(self::COPPERS.'/register', $this->data())
            ->assertSessionHasErrors(['email' => 'Este correo ya tiene una cuenta. Inicia sesión: es la misma cuenta en todas nuestras tiendas.']);

        $this->post(self::COPPERS.'/register', $this->data(['email' => 'otra@example.com', 'password_confirmation' => 'distinta']))
            ->assertSessionHasErrors('password');

        $this->post(self::COPPERS.'/register', $this->data(['name' => '', 'email' => 'no-es-correo']))
            ->assertSessionHasErrors(['name', 'email']);

        $this->assertGuest('customer');
        $this->assertSame(1, Customer::count());
    }

    public function test_an_email_registered_in_one_store_is_taken_in_the_other()
    {
        $this->post(self::COPPERS.'/register', $this->data())->assertRedirect('/');
        $this->post(self::COPPERS.'/logout');

        $this->post(self::G5.'/register', $this->data(['name' => 'Otra Ana']))->assertSessionHasErrors('email');

        $this->assertSame(1, Customer::count());
    }
}
