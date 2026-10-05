<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/** Un cliente es una sola cuenta para ambas tiendas, con sesión por dominio; y nunca se mezcla con el panel. */
class CustomerSessionsTest extends CustomerAuthTestCase
{
    public function test_a_customer_registered_in_coppers_can_log_in_to_g5_with_the_same_email()
    {
        $this->post(self::COPPERS.'/register', [
            'name' => 'Ana Pérez',
            'email' => 'ana@example.com',
            'password' => 'password-segura',
            'password_confirmation' => 'password-segura',
        ])->assertRedirect('/');

        $customer = Customer::firstWhere('email', 'ana@example.com');
        $this->assertAuthenticatedAs($customer, 'customer');

        // Se cierra la sesión de Copper's: en Sports G5 hay que iniciar sesión aparte.
        $this->post(self::COPPERS.'/logout');
        $this->assertGuest('customer');

        $this->post(self::G5.'/login', ['email' => 'ana@example.com', 'password' => 'password-segura'])->assertRedirect('/');

        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertSame(1, Customer::count());
    }

    public function test_the_session_cookie_belongs_to_each_domain()
    {
        $customer = $this->customer();

        // Sin SESSION_DOMAIN la cookie solo sirve en el dominio que la creó: coppers.localhost y
        // g5.localhost no comparten sesión, así que cada tienda pide su propio inicio de sesión.
        $this->assertNull(config('session.domain'));

        $response = $this->post(self::COPPERS.'/login', ['email' => $customer->email, 'password' => 'password']);

        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($cookie);
        $this->assertNull($cookie->getDomain());
    }

    public function test_a_customer_can_not_enter_the_admin_panel()
    {
        $customer = $this->customer();

        $this->post(self::COPPERS.'/login', ['email' => $customer->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($customer, 'customer');

        foreach (['/admin', '/admin/coppers', '/admin/coppers/products', '/admin/g5'] as $path) {
            $response = $this->get(self::COPPERS.$path);

            $response->assertRedirect();
            $this->assertStringContainsString('/admin/login', $response->headers->get('Location'), $path);
        }

        $this->assertGuest('web');
    }

    public function test_a_customer_does_not_count_as_a_panel_user()
    {
        $customer = $this->customer();

        // Aunque se fuerce la sesión del cliente, el guard del panel sigue sin usuario.
        $this->actingAs($customer, 'customer');

        $this->assertNull(Auth::guard('web')->user());
        $this->get(self::COPPERS.'/admin')->assertRedirect();
    }

    public function test_a_panel_user_can_not_log_in_as_a_customer()
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $staff = User::factory()->create(['role' => UserRole::Staff]);

        foreach ([$owner, $staff] as $user) {
            $this->post(self::COPPERS.'/login', ['email' => $user->email, 'password' => 'password'])
                ->assertSessionHasErrors('email');

            $this->assertGuest('customer');
            $this->assertGuest('web');
        }
    }

    public function test_a_panel_user_session_does_not_open_customer_pages()
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)->get(self::COPPERS.'/mi-cuenta')->assertRedirect(self::COPPERS.'/login');
        $this->actingAs($owner)->post(self::COPPERS.'/logout')->assertRedirect(self::COPPERS.'/login');

        $this->assertGuest('customer');
    }

    public function test_a_panel_user_can_not_register_a_customer_with_the_same_email_and_get_panel_access()
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'email' => 'dueno@example.com']);

        // El correo de un user no impide registrar un cliente (son tablas aparte), pero esa cuenta no abre el panel.
        $this->post(self::COPPERS.'/register', [
            'name' => 'Cliente',
            'email' => 'dueno@example.com',
            'password' => 'otra-password-1',
            'password_confirmation' => 'otra-password-1',
        ])->assertRedirect('/');

        $this->assertAuthenticated('customer');
        $this->assertGuest('web');
        $this->get(self::COPPERS.'/admin')->assertRedirect();
        $this->assertNotSame($owner->password, Customer::firstWhere('email', 'dueno@example.com')->password);
    }
}
