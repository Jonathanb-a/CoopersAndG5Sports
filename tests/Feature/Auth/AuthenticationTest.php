<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;

class AuthenticationTest extends CustomerAuthTestCase
{
    public function test_login_screen_can_be_rendered_in_each_store()
    {
        $this->get(self::COPPERS.'/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/login')->where('store.slug', 'coppers')->where('auth.customer', null));

        $this->get(self::G5.'/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('store.slug', 'g5'));
    }

    public function test_auth_pages_need_a_store_domain()
    {
        $this->get('http://otra.localhost/login')->assertNotFound();
        $this->get('http://otra.localhost/register')->assertNotFound();
    }

    public function test_customers_can_log_in_and_the_header_gets_their_name()
    {
        $customer = $this->customer(['name' => 'Ana Pérez']);

        $this->post(self::COPPERS.'/login', ['email' => $customer->email, 'password' => 'password'])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($customer, 'customer');

        $this->get(self::COPPERS.'/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.customer.name', 'Ana Pérez')
                ->where('auth.customer.email', $customer->email)
                ->missing('auth.customer.password'));
    }

    public function test_keep_me_signed_in_sets_a_remember_cookie_and_token()
    {
        $customer = $this->customer();
        $recaller = Auth::guard('customer')->getRecallerName();

        $response = $this->post(self::COPPERS.'/login', ['email' => $customer->email, 'password' => 'password', 'remember' => true]);

        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === $recaller);

        $this->assertNotNull($cookie);
        $this->assertNotEmpty($customer->fresh()->remember_token);
        $this->assertGreaterThan(now()->addDays(300)->timestamp, $cookie->getExpiresTime());
    }

    public function test_without_the_checkbox_no_remember_cookie_is_set()
    {
        $customer = $this->customer();
        $recaller = Auth::guard('customer')->getRecallerName();

        $response = $this->post(self::COPPERS.'/login', ['email' => $customer->email, 'password' => 'password']);

        $this->assertNull(collect($response->headers->getCookies())->firstWhere(fn ($cookie) => $cookie->getName() === $recaller));
        $this->assertNull($customer->fresh()->remember_token);
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    public function test_the_remember_token_is_never_sent_to_the_browser()
    {
        $customer = $this->customer();
        $this->post(self::COPPERS.'/login', ['email' => $customer->email, 'password' => 'password', 'remember' => true]);

        $this->assertArrayNotHasKey('remember_token', $customer->fresh()->toArray());
        $this->get(self::COPPERS.'/')->assertInertia(fn (Assert $page) => $page->missing('auth.customer.remember_token'));
    }

    public function test_customers_can_not_log_in_with_a_wrong_password()
    {
        $customer = $this->customer();

        $this->post(self::COPPERS.'/login', ['email' => $customer->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'Estas credenciales no coinciden con nuestros registros.']);

        $this->assertGuest('customer');
    }

    public function test_an_account_without_password_can_not_log_in_with_a_password()
    {
        $customer = $this->customer(['password' => null, 'google_id' => 'g-1']);

        $this->post(self::COPPERS.'/login', ['email' => $customer->email, 'password' => ''])->assertSessionHasErrors('password');
        $this->post(self::COPPERS.'/login', ['email' => $customer->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest('customer');
    }

    public function test_login_is_throttled_after_five_failed_attempts()
    {
        $customer = $this->customer();

        foreach (range(1, 5) as $ignored) {
            $this->post(self::COPPERS.'/login', ['email' => $customer->email, 'password' => 'mal']);
        }

        $this->post(self::COPPERS.'/login', ['email' => $customer->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('customer');
    }

    public function test_customers_can_log_out_without_touching_the_panel_session()
    {
        $customer = $this->customer();
        $staff = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($customer, 'customer')->actingAs($staff, 'web')
            ->post(self::COPPERS.'/logout')
            ->assertRedirect('/');

        $this->assertGuest('customer');
        $this->assertAuthenticatedAs($staff, 'web');
    }

    public function test_a_logged_in_customer_is_sent_away_from_the_login_page()
    {
        $this->actingAs($this->customer(), 'customer')
            ->get(self::COPPERS.'/login')
            ->assertRedirect();
    }

    public function test_google_button_is_only_offered_with_credentials()
    {
        $this->get(self::COPPERS.'/login')->assertInertia(fn (Assert $page) => $page->where('googleEnabled', false));

        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secreto']);

        $this->get(self::COPPERS.'/login')->assertInertia(fn (Assert $page) => $page->where('googleEnabled', true));
        $this->get(self::COPPERS.'/register')->assertInertia(fn (Assert $page) => $page->where('googleEnabled', true));
    }
}
