<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Mockery\MockInterface;

/** Inicio de sesión con Google. Socialite se simula: no se llama a Google. */
class GoogleLoginTest extends CustomerAuthTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'id-de-prueba', 'services.google.client_secret' => 'secreto-de-prueba']);
    }

    /** Simula a Google devolviendo este usuario, y exige que la URL de retorno sea la de la tienda actual. */
    private function google(string $callbackUrl, string $id, ?string $email, ?string $name = 'Ana Google', bool $verified = true): void
    {
        $user = (new SocialiteUser)->setRaw(['email_verified' => $verified])->map(['id' => $id, 'name' => $name, 'email' => $email]);

        /** @var AbstractProvider&MockInterface $provider */
        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('redirectUrl')->with($callbackUrl)->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($user);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_it_creates_a_customer_without_password_for_a_new_email()
    {
        $this->google(self::COPPERS.'/auth/google/callback', 'g-123', 'Ana@Example.com');

        $this->get(self::COPPERS.'/auth/google/callback')->assertRedirect('/');

        $customer = Customer::firstWhere('google_id', 'g-123');

        $this->assertNotNull($customer);
        $this->assertSame('ana@example.com', $customer->email);
        $this->assertSame('Ana Google', $customer->name);
        $this->assertNull($customer->password);
        $this->assertFalse($customer->hasPassword());
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertSame(1, Customer::count());
    }

    public function test_it_links_google_to_an_existing_customer_with_the_same_email()
    {
        $existing = $this->customer(['name' => 'Ana Pérez', 'email' => 'ana@example.com']);
        $hash = $existing->password;

        $this->google(self::G5.'/auth/google/callback', 'g-456', 'ana@example.com', 'Ana G.');

        $this->get(self::G5.'/auth/google/callback')->assertRedirect('/');

        $existing->refresh();

        $this->assertSame('g-456', $existing->google_id);
        $this->assertSame($hash, $existing->password);      // sigue pudiendo entrar con su contraseña
        $this->assertSame('Ana Pérez', $existing->name);     // no se pisan sus datos
        $this->assertSame(1, Customer::count());
        $this->assertAuthenticatedAs($existing, 'customer');
    }

    public function test_a_returning_google_customer_is_found_by_google_id()
    {
        $customer = $this->customer(['email' => 'vieja@example.com', 'password' => null, 'google_id' => 'g-789']);

        // Google ahora reporta otro correo: se reconoce por el id y no se crea otra cuenta.
        $this->google(self::COPPERS.'/auth/google/callback', 'g-789', 'nuevo@example.com');

        $this->get(self::COPPERS.'/auth/google/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertSame(1, Customer::count());
        $this->assertSame('vieja@example.com', $customer->fresh()->email);
    }

    public function test_an_unverified_google_email_is_rejected_so_nobody_can_take_over_an_account()
    {
        $victim = $this->customer(['email' => 'ana@example.com']);

        $this->google(self::COPPERS.'/auth/google/callback', 'g-evil', 'ana@example.com', verified: false);

        $this->get(self::COPPERS.'/auth/google/callback')
            ->assertRedirect(self::COPPERS.'/login')
            ->assertSessionHasErrors(['email' => 'Tu cuenta de Google no tiene un correo verificado.']);

        $this->assertGuest('customer');
        $this->assertNull($victim->fresh()->google_id);
        $this->assertSame(1, Customer::count());
    }

    public function test_a_customer_already_linked_to_another_google_account_is_rejected()
    {
        $this->customer(['email' => 'ana@example.com', 'google_id' => 'g-original']);

        $this->google(self::COPPERS.'/auth/google/callback', 'g-otra', 'ana@example.com');

        $this->get(self::COPPERS.'/auth/google/callback')->assertSessionHasErrors('email');

        $this->assertGuest('customer');
        $this->assertSame('g-original', Customer::first()->google_id);
    }

    public function test_a_google_account_without_email_is_rejected()
    {
        $this->google(self::COPPERS.'/auth/google/callback', 'g-sin-correo', null);

        $this->get(self::COPPERS.'/auth/google/callback')->assertSessionHasErrors('email');

        $this->assertSame(0, Customer::count());
    }

    public function test_cancelling_at_google_returns_to_login_with_a_message()
    {
        $this->get(self::COPPERS.'/auth/google/callback?error=access_denied')
            ->assertRedirect(self::COPPERS.'/login')
            ->assertSessionHasErrors(['email' => 'Cancelaste el inicio de sesión con Google.']);

        $this->assertGuest('customer');
    }

    public function test_an_invalid_state_returns_to_login_with_a_message()
    {
        /** @var AbstractProvider&MockInterface $provider */
        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new InvalidStateException);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(self::COPPERS.'/auth/google/callback')->assertSessionHasErrors('email');

        $this->assertGuest('customer');
    }

    public function test_the_redirect_uses_the_current_stores_domain()
    {
        foreach ([self::COPPERS, self::G5] as $base) {
            /** @var AbstractProvider&MockInterface $provider */
            $provider = Mockery::mock(AbstractProvider::class);
            $provider->shouldReceive('redirectUrl')->once()->with($base.'/auth/google/callback')->andReturnSelf();
            $provider->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth'));
            Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

            $this->get($base.'/auth/google')->assertRedirect('https://accounts.google.com/o/oauth2/auth');
        }
    }

    public function test_without_credentials_the_google_routes_do_not_exist()
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $this->get(self::COPPERS.'/auth/google')->assertNotFound();
        $this->get(self::COPPERS.'/auth/google/callback')->assertNotFound();

        config(['services.google.client_id' => 'id', 'services.google.client_secret' => '']);

        $this->get(self::COPPERS.'/auth/google')->assertNotFound();
    }
}
