<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Notifications\CustomerResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

class PasswordResetTest extends CustomerAuthTestCase
{
    public function test_reset_password_link_screen_can_be_rendered()
    {
        $this->get(self::COPPERS.'/forgot-password')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/forgot-password'));
    }

    public function test_the_reset_link_is_sent_to_customers_only()
    {
        Notification::fake();
        $customer = $this->customer();

        $this->post(self::COPPERS.'/forgot-password', ['email' => $customer->email])
            ->assertSessionHas('status', 'Si el correo tiene una cuenta, te enviamos un enlace para restablecer tu contraseña.');

        Notification::assertSentTo($customer, CustomerResetPassword::class);

        // Mismo mensaje con un correo que no existe, y no se manda nada.
        Notification::fake();
        $this->post(self::COPPERS.'/forgot-password', ['email' => 'nadie@example.com'])
            ->assertSessionHas('status', 'Si el correo tiene una cuenta, te enviamos un enlace para restablecer tu contraseña.');
        Notification::assertNothingSent();
    }

    public function test_the_email_carries_the_current_stores_name_and_domain()
    {
        $customer = $this->customer(['email' => 'ana@example.com']);

        $this->post(self::G5.'/forgot-password', ['email' => 'ana@example.com']);

        $messages = $this->app->make('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);

        $message = $messages[0]->getOriginalMessage();

        $this->assertSame('Restablece tu contraseña de Sports G5', $message->getSubject());
        $this->assertSame('Sports G5', $message->getFrom()[0]->getName());
        $this->assertStringContainsString('Sports G5', $message->getHtmlBody());
        $this->assertStringContainsString('http://g5.localhost/reset-password/', $message->getHtmlBody());
        $this->assertStringNotContainsString('coppers.localhost', $message->getHtmlBody());
        $this->assertSame('ana@example.com', $message->getTo()[0]->getAddress());
    }

    public function test_the_password_can_be_reset_with_a_valid_token_only_once()
    {
        $customer = $this->customer();
        $token = Password::broker('customers')->createToken($customer);

        $this->get(self::COPPERS.'/reset-password/'.$token.'?email='.urlencode($customer->email))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/reset-password')->where('token', $token)->where('email', $customer->email));

        $this->post(self::COPPERS.'/reset-password', [
            'token' => $token,
            'email' => $customer->email,
            'password' => 'nueva-contraseña',
            'password_confirmation' => 'nueva-contraseña',
        ])->assertRedirect(self::COPPERS.'/login')->assertSessionHas('status');

        $this->assertTrue(Hash::check('nueva-contraseña', $customer->fresh()->password));

        $this->post(self::COPPERS.'/reset-password', [
            'token' => $token,
            'email' => $customer->email,
            'password' => 'otra-contraseña',
            'password_confirmation' => 'otra-contraseña',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('nueva-contraseña', $customer->fresh()->password));
    }

    public function test_a_token_from_another_email_does_not_work()
    {
        $customer = $this->customer();
        $other = $this->customer();
        $token = Password::broker('customers')->createToken($customer);

        $this->post(self::COPPERS.'/reset-password', [
            'token' => $token,
            'email' => $other->email,
            'password' => 'nueva-contraseña',
            'password_confirmation' => 'nueva-contraseña',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('password', $other->fresh()->password));
    }

    public function test_an_account_created_with_google_can_set_a_password_by_email()
    {
        $customer = $this->customer(['password' => null, 'google_id' => 'g-1']);
        $token = Password::broker('customers')->createToken($customer);

        $this->post(self::COPPERS.'/reset-password', [
            'token' => $token,
            'email' => $customer->email,
            'password' => 'nueva-contraseña',
            'password_confirmation' => 'nueva-contraseña',
        ])->assertRedirect(self::COPPERS.'/login');

        $this->assertTrue(Customer::find($customer->id)->hasPassword());
    }
}
