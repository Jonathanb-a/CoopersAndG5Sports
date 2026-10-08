<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerResetPassword;
use App\Support\StoreMailTheme;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/** Recuperar la contraseña de un cliente: flujo, seguridad, correo con la marca de la tienda y sesiones. */
class PasswordRecoveryTest extends CustomerAuthTestCase
{
    private const NEW_PASSWORD = 'contraseña-nueva-123';

    private function ask(string $email, string $host = self::COPPERS)
    {
        return $this->post("{$host}/forgot-password", ['email' => $email]);
    }

    private function reset(string $token, string $email, string $host = self::COPPERS, string $password = self::NEW_PASSWORD)
    {
        return $this->post("{$host}/reset-password", [
            'token' => $token,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    /** El correo que recibió el cliente, ya armado. */
    private function sentMail(Customer $customer, string $host = self::COPPERS)
    {
        // Cada correo parte de cero: el broker no deja pedir otro enlace al mismo cliente en un minuto.
        DB::table('customer_password_reset_tokens')->delete();

        Notification::fake();
        $this->ask($customer->email, $host);

        $found = null;
        Notification::assertSentTo($customer, CustomerResetPassword::class, function (CustomerResetPassword $notification) use ($customer, &$found) {
            $found = [$notification, $notification->toMail($customer)];

            return true;
        });

        return $found;
    }

    // ---- Flujo completo ---------------------------------------------------------------------------

    public function test_the_whole_flow_from_the_login_link_to_the_new_password()
    {
        Notification::fake();
        $customer = $this->customer(['email' => 'ana@example.com']);

        // El inicio de sesión lleva al formulario de correo.
        $this->get(self::COPPERS.'/login')->assertOk();
        $this->assertStringContainsString('/forgot-password', file_get_contents(resource_path('js/pages/auth/login.tsx')));
        $this->get(self::COPPERS.'/forgot-password')->assertOk();

        $this->ask('ana@example.com')->assertSessionHas('status', PasswordResetLinkController::STATUS);

        $token = null;
        Notification::assertSentTo($customer, CustomerResetPassword::class, function (CustomerResetPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        // El formulario de contraseña nueva se abre con el enlace del correo.
        $this->get(self::COPPERS."/reset-password/{$token}?email=ana%40example.com")->assertOk();

        $this->reset($token, 'ana@example.com')->assertRedirect(self::COPPERS.'/login')->assertSessionHas('status', 'Tu contraseña se restableció. Ya puedes iniciar sesión.');

        // La contraseña vieja ya no sirve y la nueva sí.
        $this->post(self::COPPERS.'/login', ['email' => 'ana@example.com', 'password' => 'password'])->assertSessionHasErrors();
        $this->assertGuest('customer');
        $this->post(self::COPPERS.'/login', ['email' => 'ana@example.com', 'password' => self::NEW_PASSWORD])->assertRedirect('/');
        $this->assertAuthenticatedAs($customer, 'customer');
    }

    // ---- Seguridad: mismo mensaje, caducidad, un solo uso -----------------------------------------

    public function test_the_message_is_the_same_whether_or_not_the_account_exists()
    {
        Notification::fake();
        $this->customer(['email' => 'ana@example.com']);
        $google = $this->customer(['email' => 'gabo@example.com', 'password' => null, 'google_id' => 'g-1']);

        $message = 'Si el correo existe, te enviamos un enlace para restablecer tu contraseña.';
        $this->assertSame($message, PasswordResetLinkController::STATUS);

        foreach (['ana@example.com', 'gabo@example.com', 'nadie@example.com'] as $email) {
            $this->ask($email)->assertRedirect()->assertSessionHas('status', $message)->assertSessionHasNoErrors();
        }

        // Pedirlo otra vez enseguida (el broker lo limita a una vez por minuto) responde igual.
        $this->ask('ana@example.com')->assertSessionHas('status', $message)->assertSessionHasNoErrors();

        Notification::assertSentTimes(CustomerResetPassword::class, 2);
        Notification::assertSentTo($google, CustomerResetPassword::class);
        Notification::assertNotSentTo($this->customer(), CustomerResetPassword::class);

        // En la pantalla de contraseña nueva, un correo inexistente y un token malo dan el mismo aviso.
        $token = Password::broker('customers')->createToken(Customer::firstWhere('email', 'ana@example.com'));
        $invalid = 'El enlace para restablecer tu contraseña no es válido o ya venció. Pide uno nuevo.';

        $this->reset($token, 'nadie@example.com')->assertSessionHasErrors(['email' => $invalid]);
        $this->reset('token-falso', 'ana@example.com')->assertSessionHasErrors(['email' => $invalid]);
    }

    public function test_the_link_expires_after_sixty_minutes()
    {
        $customer = $this->customer(['email' => 'ana@example.com']);
        $this->assertSame(60, config('auth.passwords.customers.expire'));
        $invalid = 'El enlace para restablecer tu contraseña no es válido o ya venció. Pide uno nuevo.';

        $expired = Password::broker('customers')->createToken($customer);
        $this->travel(61)->minutes();
        $this->reset($expired, 'ana@example.com')->assertSessionHasErrors(['email' => $invalid]);
        $this->assertTrue(Hash::check('password', $customer->fresh()->password));

        // A los 59 minutos todavía sirve.
        $this->travelBack();
        $valid = Password::broker('customers')->createToken($customer);
        $this->travel(59)->minutes();
        $this->reset($valid, 'ana@example.com')->assertRedirect(self::COPPERS.'/login');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $customer->fresh()->password));
    }

    public function test_the_link_works_once_and_a_new_request_replaces_the_old_link()
    {
        $customer = $this->customer(['email' => 'ana@example.com']);
        $table = 'customer_password_reset_tokens';

        $first = Password::broker('customers')->createToken($customer);
        $second = Password::broker('customers')->createToken($customer);

        // Pedir otro enlace deja sin efecto el anterior.
        $this->reset($first, 'ana@example.com')->assertSessionHasErrors('email');
        $this->assertSame(1, DB::table($table)->where('email', 'ana@example.com')->count());

        $this->reset($second, 'ana@example.com')->assertRedirect(self::COPPERS.'/login');
        $this->assertSame(0, DB::table($table)->where('email', 'ana@example.com')->count());

        // Y ya usado, no se puede volver a usar.
        $this->reset($second, 'ana@example.com', password: 'otra-contraseña-456')->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $customer->fresh()->password));
    }

    public function test_requesting_links_is_throttled()
    {
        Notification::fake();
        $this->customer(['email' => 'ana@example.com']);
        $message = 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.';

        // 5 por minuto para el mismo correo y la misma dirección IP.
        foreach (range(1, 5) as $ignored) {
            $this->ask('ana@example.com')->assertSessionHas('status')->assertSessionHasNoErrors();
        }

        $this->ask('ana@example.com')->assertSessionHasErrors(['email' => $message])->assertSessionMissing('status');
        $this->ask('ANA@example.com')->assertSessionHasErrors(['email' => $message]);

        // Otro correo no se ve afectado.
        $this->ask('otra@example.com')->assertSessionHas('status');

        // Y un minuto después se puede otra vez.
        $this->travel(61)->seconds();
        $this->ask('ana@example.com')->assertSessionHas('status')->assertSessionHasNoErrors();
    }

    public function test_requesting_links_from_one_address_is_limited_per_hour()
    {
        Notification::fake();

        foreach (range(1, 30) as $number) {
            $this->ask("cuenta{$number}@example.com")->assertSessionHas('status');
        }

        $this->ask('cuenta31@example.com')->assertSessionHasErrors('email')->assertSessionMissing('status');
    }

    public function test_setting_the_new_password_is_throttled_too()
    {
        foreach (range(1, 10) as $ignored) {
            $this->reset('token-falso', 'ana@example.com')->assertSessionHasErrors('email');
        }

        $this->reset('token-falso', 'ana@example.com')->assertSessionHasErrors(['email' => 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.']);
    }

    public function test_the_email_must_look_like_an_email()
    {
        Notification::fake();

        $this->ask('esto-no-es-un-correo')->assertSessionHasErrors('email');
        $this->post(self::COPPERS.'/forgot-password', [])->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    // ---- Correo: en cola, dominio y marca de la tienda --------------------------------------------

    public function test_the_notification_is_queued()
    {
        $this->assertInstanceOf(ShouldQueue::class, new CustomerResetPassword('t', 'http://coppers.localhost', "Copper's", 'coppers'));

        Queue::fake();
        $customer = $this->customer(['email' => 'ana@example.com']);

        $this->ask('ana@example.com');

        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof CustomerResetPassword);
        $this->assertSame($customer->id, Customer::firstWhere('email', 'ana@example.com')->id);
    }

    public function test_the_link_opens_the_domain_of_the_store_that_asked_even_when_sent_from_the_queue()
    {
        $customer = $this->customer(['email' => 'ana@example.com']);

        [$notification, $mail] = $this->sentMail($customer, self::G5);

        // La tienda queda fija en la notificación: el trabajo en cola ya no tiene la petición.
        $this->assertSame(['http://g5.localhost', 'Sports G5', 'g5'], [$notification->baseUrl, $notification->storeName, $notification->theme]);

        $html = (string) $mail->render();
        $this->assertStringContainsString("http://g5.localhost/reset-password/{$notification->token}?email=ana%40example.com", $html);
        $this->assertStringNotContainsString('coppers.localhost', $html);

        [$coppers, $coppersMail] = $this->sentMail($customer);
        $this->assertStringContainsString('http://coppers.localhost/reset-password/', (string) $coppersMail->render());
        $this->assertStringNotContainsString('g5.localhost', (string) $coppersMail->render());

        // Con puerto (desarrollo local): se conserva.
        [$withPort, $portMail] = $this->sentMail($customer, 'http://g5.localhost:8000');
        $this->assertSame('http://g5.localhost:8000', $withPort->baseUrl);
        $this->assertStringContainsString('http://g5.localhost:8000/reset-password/', (string) $portMail->render());
    }

    public function test_the_email_is_in_spanish_with_the_store_name_and_colors()
    {
        $customer = $this->customer(['email' => 'ana@example.com']);

        [, $g5] = $this->sentMail($customer, self::G5);
        $html = (string) $g5->render();

        $this->assertSame('Restablece tu contraseña de Sports G5', $g5->subject);
        $this->assertSame('Sports G5', $g5->from[1]);
        foreach (['Sports G5', 'Restablece tu contraseña', 'vence en 60 minutos', 'solo se puede usar una vez', 'Si no lo pediste, ignora este correo', 'lang="es-MX"'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        // Colores del tema G5: fondo oscuro y turquesa con texto negro.
        foreach (['#0E0E10', '#35F0EE', 'uppercase'] as $style) {
            $this->assertStringContainsString($style, $html);
        }
        $this->assertStringNotContainsString('#B8692A', $html);

        [, $coppers] = $this->sentMail($customer);
        $copperHtml = (string) $coppers->render();

        $this->assertSame('Restablece tu contraseña de Copper\'s', $coppers->subject);
        foreach (['#FAF7F2', '#B8692A', 'Cormorant Garamond'] as $style) {
            $this->assertStringContainsString($style, $copperHtml);
        }
        $this->assertStringNotContainsString('#35F0EE', $copperHtml);

        // Sin archivo de logo en public/brand, el correo muestra el nombre de la tienda.
        $this->assertNull(StoreMailTheme::logoUrl('g5', 'http://g5.localhost'));
        $this->assertNull(StoreMailTheme::logoUrl('desconocido', 'http://g5.localhost'));
    }

    public function test_the_text_version_carries_the_link_too()
    {
        $customer = $this->customer(['email' => 'ana@example.com']);

        [$notification, $mail] = $this->sentMail($customer, self::G5);

        $text = view($mail->view[1], $mail->viewData)->render();

        $this->assertStringContainsString("http://g5.localhost/reset-password/{$notification->token}", $text);
        $this->assertStringContainsString('Sports G5', $text);
        $this->assertStringContainsString('vence en 60 minutos', $text);
    }

    public function test_the_mail_goes_out_through_the_mailer_with_the_store_data()
    {
        $this->customer(['email' => 'ana@example.com']);

        $this->ask('ana@example.com', self::G5);

        $messages = $this->app->make('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $mail = $messages[0]->getOriginalMessage();

        $this->assertSame('Restablece tu contraseña de Sports G5', $mail->getSubject());
        $this->assertSame('ana@example.com', $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('http://g5.localhost/reset-password/', $mail->getHtmlBody());
        $this->assertStringContainsString('http://g5.localhost/reset-password/', $mail->getTextBody());
    }

    // ---- Si el envío falla ------------------------------------------------------------------------

    public function test_a_failed_send_is_logged_and_the_screen_does_not_break()
    {
        $this->customer(['email' => 'ana@example.com']);
        Log::spy();

        Password::shouldReceive('broker')->with('customers')->andThrow(new RuntimeException('Connection refused'));

        $this->ask('ana@example.com')
            ->assertRedirect()
            ->assertSessionHas('status', PasswordResetLinkController::STATUS)
            ->assertSessionHasNoErrors();

        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'Connection refused'))->once();
    }

    public function test_a_queued_mail_that_keeps_failing_is_logged_without_the_link()
    {
        Log::spy();
        $notification = new CustomerResetPassword('token-secreto', 'http://g5.localhost', 'Sports G5', 'g5');

        $notification->failed(new RuntimeException('SMTP caído'));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => str_contains($message, 'SMTP caído')
            && str_contains($message, 'Sports G5')
            && ! str_contains($message, 'token-secreto'))->once();
        $this->assertSame(3, $notification->tries);
    }

    // ---- Cuentas de Google ------------------------------------------------------------------------

    public function test_an_account_created_with_google_can_define_a_password_and_then_enter_with_email()
    {
        $google = $this->customer(['email' => 'gabo@example.com', 'password' => null, 'google_id' => 'g-1', 'name' => 'Gabo']);
        $this->assertFalse($google->hasPassword());

        // Entrar con correo todavía no se puede, y no dice por qué.
        $this->post(self::COPPERS.'/login', ['email' => 'gabo@example.com', 'password' => 'lo-que-sea'])->assertSessionHasErrors();

        [$notification, $mail] = $this->sentMail($google);

        // El correo explica que es para crear una contraseña.
        $html = (string) $mail->render();
        $this->assertStringContainsString('se creó con Google', $html);
        $this->assertStringContainsString('Crea tu contraseña', $html);

        $this->reset($notification->token, 'gabo@example.com')->assertRedirect(self::COPPERS.'/login');

        $google->refresh();
        $this->assertTrue($google->hasPassword());
        $this->assertSame('g-1', $google->google_id);
        $this->post(self::COPPERS.'/login', ['email' => 'gabo@example.com', 'password' => self::NEW_PASSWORD])->assertRedirect('/');
        $this->assertAuthenticatedAs($google, 'customer');

        // Un correo normal no lleva ese texto (ya sin la sesión de Gabo: pedir el enlace es de invitados).
        $this->forgetBrowser();
        $normal = $this->customer(['email' => 'ana@example.com']);
        [, $normalMail] = $this->sentMail($normal);
        $this->assertStringNotContainsString('se creó con Google', (string) $normalMail->render());
    }

    // ---- Otras sesiones ---------------------------------------------------------------------------

    /** Como cerrar el navegador: sin sesión y sin el cliente que el guard tenía en memoria. */
    private function forgetBrowser(): void
    {
        $this->flushSession();
        Auth::guard('customer')->forgetUser();
    }

    /** Inicia sesión y devuelve los datos de esa sesión, como si fuera otro aparato. */
    private function loginOnAnotherDevice(Customer $customer): array
    {
        $this->post(self::COPPERS.'/login', ['email' => $customer->email, 'password' => 'password'])->assertRedirect('/');
        $session = session()->all();

        $this->forgetBrowser();

        return $session;
    }

    public function test_resetting_the_password_closes_the_other_sessions_but_keeps_their_cart()
    {
        $customer = $this->customer(['email' => 'ana@example.com']);
        $cartKey = 'shop.cart.'.$this->coppers->id;

        $other = $this->loginOnAnotherDevice($customer);
        $other[$cartKey] = [7 => 2];

        // Mientras no cambie la contraseña, esa sesión sigue entrando.
        $this->withSession($other)->get(self::COPPERS.'/mi-cuenta')->assertOk();
        $this->forgetBrowser();

        // Desde otro navegador se restablece la contraseña.
        $token = Password::broker('customers')->createToken($customer);
        $this->reset($token, 'ana@example.com')->assertRedirect(self::COPPERS.'/login');
        $this->forgetBrowser();

        // La sesión anterior ya no entra: la manda a iniciar sesión, y el carrito se conserva.
        $this->withSession($other)->get(self::COPPERS.'/mi-cuenta')->assertRedirect(self::COPPERS.'/login');
        $this->assertGuest('customer');
        $this->assertSame([7 => 2], session($cartKey));
    }

    public function test_the_remember_cookie_stops_working_after_a_reset()
    {
        $customer = $this->customer(['email' => 'ana@example.com', 'remember_token' => 'token-viejo']);
        $token = Password::broker('customers')->createToken($customer);

        $this->reset($token, 'ana@example.com')->assertRedirect(self::COPPERS.'/login');

        $this->assertNotSame('token-viejo', $customer->fresh()->remember_token);
    }

    public function test_changing_the_password_in_my_account_closes_the_other_sessions_and_keeps_this_one()
    {
        $customer = $this->customer(['email' => 'ana@example.com']);
        $other = $this->loginOnAnotherDevice($customer);

        $this->post(self::COPPERS.'/login', ['email' => 'ana@example.com', 'password' => 'password']);
        $this->put(self::COPPERS.'/mi-cuenta/contrasena', [
            'current_password' => 'password',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasNoErrors();

        // Esta sesión sigue dentro.
        $this->get(self::COPPERS.'/mi-cuenta')->assertOk();
        $this->assertAuthenticatedAs($customer, 'customer');

        // La otra se cierra.
        $this->forgetBrowser();
        $this->withSession($other)->get(self::COPPERS.'/mi-cuenta')->assertRedirect(self::COPPERS.'/login');
    }

    public function test_a_google_session_is_closed_when_the_password_is_defined()
    {
        $google = $this->customer(['email' => 'gabo@example.com', 'password' => null, 'google_id' => 'g-1']);
        $this->actingAs($google, 'customer');
        $this->get(self::COPPERS.'/mi-cuenta')->assertOk();
        $session = session()->all();
        $this->assertArrayHasKey('customer_password_hash', $session);

        $google->update(['password' => self::NEW_PASSWORD]);

        $this->forgetBrowser();
        $this->withSession($session)->get(self::COPPERS.'/mi-cuenta')->assertRedirect(self::COPPERS.'/login');
    }

    // ---- Los clientes y el panel van separados ----------------------------------------------------

    public function test_the_customer_broker_does_not_serve_panel_users()
    {
        $staff = User::factory()->create(['email' => 'ana@example.com', 'role' => UserRole::Staff, 'password' => Hash::make('clave-del-panel')]);
        Notification::fake();

        // El broker de clientes no encuentra a un empleado, ni manda nada.
        $this->assertSame(Password::INVALID_USER, Password::broker('customers')->sendResetLink(['email' => $staff->email]));
        $this->ask($staff->email)->assertSessionHas('status', PasswordResetLinkController::STATUS);
        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('customer_password_reset_tokens')->count());

        // Un token del broker del panel no restablece nada por la puerta de clientes.
        $staffToken = Password::broker('users')->createToken($staff);
        $this->reset($staffToken, $staff->email)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('clave-del-panel', $staff->fresh()->password));

        // Y el token de un cliente no sirve para el broker del panel.
        $customer = $this->customer(['email' => 'cliente@example.com']);
        $customerToken = Password::broker('customers')->createToken($customer);
        $this->assertFalse(Password::broker('users')->tokenExists($staff, $customerToken));

        // Cada broker usa su propia tabla.
        $this->assertSame('customer_password_reset_tokens', config('auth.passwords.customers.table'));
        $this->assertSame('password_reset_tokens', config('auth.passwords.users.table'));
        $this->assertSame(1, DB::table('customer_password_reset_tokens')->count());
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
    }

    public function test_the_panel_login_and_its_session_are_untouched()
    {
        $staff = User::factory()->create(['role' => UserRole::Owner]);

        $this->get(self::COPPERS.'/admin/login')->assertOk();

        $this->actingAs($staff, 'web');
        $this->get(self::COPPERS.'/admin')->assertRedirect();
        $this->assertAuthenticatedAs($staff, 'web');
        $this->assertNull(Auth::guard('customer')->user());
        $this->assertArrayNotHasKey('customer_password_hash', session()->all());
    }
}
