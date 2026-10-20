<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Filament\Auth\EditProfile;
use App\Filament\Auth\Login;
use App\Filament\Auth\RequestPasswordReset;
use App\Filament\Auth\ResetPassword;
use App\Filament\Resources\Users\Actions\SendPasswordLinkAction;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Notifications\PanelResetPassword;
use App\Support\PanelPasswordLink;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/** Recuperar y cambiar la contraseña del personal del panel (tabla users). La de clientes tiene sus propias pruebas. */
class PanelPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const OLD = 'clave-vieja-123';

    private const NEW = 'clave-nueva-456';

    private Store $coppers;

    private Store $g5;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);

        $this->owner = User::factory()->create(['email' => 'dueno@example.com', 'role' => UserRole::Owner, 'password' => self::OLD]);
        $this->staff = User::factory()->create(['email' => 'laura@example.com', 'role' => UserRole::Staff, 'password' => self::OLD]);
        $this->staff->stores()->attach($this->g5);

        Filament::setCurrentPanel('admin');
    }

    /** El enlace que se le mandó a alguien, tal cual lo recibió (la notificación queda registrada, no se manda). */
    private function requestLink(string $email, string $host = 'coppers.localhost'): ?PanelResetPassword
    {
        Notification::fake();
        // Un enlace por minuto por usuario (broker): cada petición de la prueba arranca sin el anterior.
        DB::table('password_reset_tokens')->delete();

        URL::forceRootUrl("http://{$host}");
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => $email])->call('request');

        $sent = Notification::sent(User::where('email', $email)->first() ?? new User, PanelResetPassword::class);

        return $sent->first();
    }

    private function resetWith(string $token, string $email, string $password = self::NEW)
    {
        return Livewire::test(ResetPassword::class, ['token' => $token, 'email' => $email])
            ->fillForm(['password' => $password, 'passwordConfirmation' => $password])
            ->call('resetPassword');
    }

    // ---- Pedir enlace y cambiar la clave -------------------------------------------------------------

    public function test_the_whole_flow_from_asking_for_the_link_to_the_new_password()
    {
        $notification = $this->requestLink('laura@example.com');

        $this->assertNotNull($notification);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'laura@example.com')->count());

        $this->resetWith($notification->token, 'laura@example.com')->assertRedirect();

        $this->assertTrue(Hash::check(self::NEW, $this->staff->fresh()->password));
    }

    public function test_the_panel_tokens_have_their_own_table_and_expire_in_sixty_minutes()
    {
        $this->requestLink('laura@example.com');

        $this->assertSame('password_reset_tokens', config('auth.passwords.users.table'));
        $this->assertSame('customer_password_reset_tokens', config('auth.passwords.customers.table'));
        $this->assertSame(60, config('auth.passwords.users.expire'));
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
        $this->assertSame(0, DB::table('customer_password_reset_tokens')->count());
    }

    public function test_an_expired_token_fails_and_the_password_stays()
    {
        $notification = $this->requestLink('laura@example.com');

        $this->travel(61)->minutes();

        $this->resetWith($notification->token, 'laura@example.com')->assertNotified(__('passwords.token'));

        $this->assertTrue(Hash::check(self::OLD, $this->staff->fresh()->password));
    }

    public function test_a_token_works_once_and_cannot_be_reused()
    {
        $notification = $this->requestLink('laura@example.com');

        $this->resetWith($notification->token, 'laura@example.com')->assertRedirect();
        $this->resetWith($notification->token, 'laura@example.com', 'otra-clave-789')->assertNotified(__('passwords.token'));

        $this->assertTrue(Hash::check(self::NEW, $this->staff->fresh()->password));
    }

    public function test_a_wrong_token_is_rejected_and_an_unknown_email_gets_the_same_error()
    {
        $this->requestLink('laura@example.com');

        $this->resetWith('token-falso', 'laura@example.com')->assertNotified(__('passwords.token'));
        $this->resetWith('token-falso', 'no-existe@example.com')->assertNotified(__('passwords.token'));
    }

    // ---- Dominio y marca -----------------------------------------------------------------------------

    public function test_the_link_opens_the_domain_it_was_asked_from_and_carries_that_stores_brand()
    {
        $fromG5 = $this->requestLink('laura@example.com', 'g5.localhost');
        $this->assertStringStartsWith('http://g5.localhost/admin/password-reset/reset?', $fromG5->url);
        $this->assertSame('Sports G5', $fromG5->storeName);

        $html = $fromG5->toMail($this->staff)->render();
        $this->assertStringContainsString('Sports G5', (string) $html);
        $this->assertStringContainsString('#35F0EE', (string) $html);
        $this->assertStringContainsString(e($fromG5->url), (string) $html);

        $fromCoppers = $this->requestLink('laura@example.com', 'coppers.localhost');
        $this->assertStringStartsWith('http://coppers.localhost/admin/password-reset/reset?', $fromCoppers->url);
        $this->assertSame("Copper's", $fromCoppers->storeName);
        $this->assertStringContainsString('#B8692A', (string) $fromCoppers->toMail($this->staff)->render());
        $this->assertStringContainsString('token='.$fromCoppers->token, $fromCoppers->url);
    }

    public function test_the_email_is_queued_and_in_spanish()
    {
        $notification = $this->requestLink('laura@example.com');

        $this->assertInstanceOf(ShouldQueue::class, $notification);

        $mail = $notification->toMail($this->staff);
        $this->assertSame("Restablece tu contraseña del panel de {$notification->storeName}", $mail->subject);
        $this->assertStringContainsString('panel de administración', (string) $mail->render());
    }

    // ---- Lo que no se revela -------------------------------------------------------------------------

    public function test_an_unknown_email_gets_the_same_message_and_nothing_is_sent()
    {
        Notification::fake();

        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'dueno@example.com'])->call('request')->assertNotified(PanelPasswordLink::SENT);
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'no-existe@example.com'])->call('request')->assertNotified(PanelPasswordLink::SENT);

        Notification::assertSentToTimes($this->owner, PanelResetPassword::class, 1);
        Notification::assertCount(1);
    }

    public function test_a_second_request_inside_the_minute_looks_the_same_to_the_outside()
    {
        Notification::fake();

        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'laura@example.com'])->call('request')->assertNotified(PanelPasswordLink::SENT);
        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'laura@example.com'])->call('request')->assertNotified(PanelPasswordLink::SENT);

        Notification::assertSentToTimes($this->staff, PanelResetPassword::class, 1);
    }

    public function test_a_customer_email_does_not_get_a_panel_link()
    {
        Notification::fake();
        Customer::factory()->create(['email' => 'ana@example.com']);

        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'ana@example.com'])->call('request')->assertNotified(PanelPasswordLink::SENT);

        Notification::assertNothingSent();
    }

    // ---- Límites -------------------------------------------------------------------------------------

    public function test_asking_for_links_is_limited_per_email_and_address()
    {
        Notification::fake();

        foreach (range(1, 5) as $ignored) {
            Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'laura@example.com'])->call('request')->assertNotified(PanelPasswordLink::SENT);
        }

        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'laura@example.com'])->call('request')->assertNotified(PanelPasswordLink::THROTTLED);

        RateLimiter::clear('panel-reset-link:'.sha1('laura@example.com|127.0.0.1'));
        foreach (range(1, 30) as $number) {
            RateLimiter::hit('panel-reset-link-ip:127.0.0.1', 3600);
        }

        Livewire::test(RequestPasswordReset::class)->fillForm(['email' => 'otro@example.com'])->call('request')->assertNotified(PanelPasswordLink::THROTTLED);
    }

    public function test_setting_the_new_password_is_limited_too()
    {
        foreach (range(1, 5) as $ignored) {
            $this->resetWith('token-falso', 'laura@example.com')->assertNotified(__('passwords.token'));
        }

        $this->resetWith('token-falso', 'laura@example.com')->assertNotified('Demasiados intentos. Espera unos minutos e inténtalo de nuevo.');
    }

    public function test_the_panel_login_blocks_after_five_failed_attempts_even_with_the_right_password()
    {
        foreach (range(1, 5) as $ignored) {
            Livewire::test(Login::class)->fillForm(['email' => 'laura@example.com', 'password' => 'mala'])->call('authenticate')->assertHasFormErrors(['email']);
        }

        Livewire::test(Login::class)->fillForm(['email' => 'laura@example.com', 'password' => self::OLD])->call('authenticate')
            ->assertNotified('Demasiados intentos. Espera unos minutos e inténtalo de nuevo.');

        $this->assertGuest();
    }

    public function test_a_good_login_still_works_and_clears_the_failures()
    {
        Livewire::test(Login::class)->fillForm(['email' => 'laura@example.com', 'password' => 'mala'])->call('authenticate')->assertHasFormErrors(['email']);
        Livewire::test(Login::class)->fillForm(['email' => 'laura@example.com', 'password' => self::OLD])->call('authenticate')->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($this->staff);
    }

    // ---- Perfil --------------------------------------------------------------------------------------

    public function test_the_profile_page_is_there_for_owner_and_staff_and_the_store_profile_is_untouched()
    {
        $this->actingAs($this->staff)->get('/admin/mi-perfil')->assertOk();
        $this->flushSession()->actingAs($this->owner)->get('/admin/mi-perfil')->assertOk();

        $this->flushSession()->actingAs($this->staff)->get('/admin/g5/profile')->assertNotFound();
        $this->flushSession()->actingAs($this->owner)->get('/admin/coppers/profile')->assertOk();
    }

    public function test_the_profile_changes_name_and_email_with_the_current_password()
    {
        $this->actingAs($this->staff);

        Livewire::test(EditProfile::class)
            ->fillForm(['name' => 'Laura Nueva', 'email' => 'laura.nueva@example.com', 'currentPassword' => self::OLD])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Laura Nueva', $this->staff->fresh()->name);
        $this->assertSame('laura.nueva@example.com', $this->staff->fresh()->email);
    }

    public function test_changing_the_password_asks_for_the_current_one()
    {
        $this->actingAs($this->staff);

        Livewire::test(EditProfile::class)
            ->fillForm(['password' => self::NEW, 'passwordConfirmation' => self::NEW])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        Livewire::test(EditProfile::class)
            ->fillForm(['password' => self::NEW, 'passwordConfirmation' => self::NEW, 'currentPassword' => 'no-es-esa'])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        $this->assertTrue(Hash::check(self::OLD, $this->staff->fresh()->password));
    }

    public function test_changing_the_password_closes_the_other_sessions_and_keeps_this_one()
    {
        $oldHash = $this->staff->password;

        $this->actingAs($this->staff)->withSession(['password_hash_web' => $oldHash]);
        $this->get('/admin/g5')->assertOk();

        Livewire::test(EditProfile::class)
            ->fillForm(['password' => self::NEW, 'passwordConfirmation' => self::NEW, 'currentPassword' => self::OLD])
            ->call('save')
            ->assertHasNoFormErrors();

        $staff = $this->staff->fresh();
        $this->assertTrue(Hash::check(self::NEW, $staff->password));

        // Esta sesión (ya con la huella nueva) sigue entrando.
        $this->flushSession()->actingAs($staff)->withSession(['password_hash_web' => $staff->password]);
        $this->get('/admin/g5')->assertOk();

        // Otro navegador, que se abrió con la contraseña vieja, queda fuera.
        $this->flushSession()->actingAs($staff)->withSession(['password_hash_web' => $oldHash]);
        $this->get('/admin/g5')->assertRedirect('/admin/login');
    }

    public function test_resetting_with_the_link_closes_the_sessions_opened_with_the_old_password()
    {
        $oldHash = $this->staff->password;
        $oldRemember = $this->staff->remember_token;

        $notification = $this->requestLink('laura@example.com');
        $this->resetWith($notification->token, 'laura@example.com')->assertRedirect();

        $staff = $this->staff->fresh();
        $this->assertNotSame($oldRemember, $staff->remember_token);

        $this->flushSession()->actingAs($staff)->withSession(['password_hash_web' => $oldHash]);
        $this->get('/admin/g5')->assertRedirect('/admin/login');
    }

    // ---- El owner restablece la clave de un empleado -------------------------------------------------

    public function test_the_owner_sends_a_staff_member_the_link_from_the_users_list()
    {
        Notification::fake();
        $this->actingAs($this->owner);
        Filament::setTenant($this->coppers);

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('sendPasswordLink', $this->staff)
            ->callTableAction('sendPasswordLink', $this->staff)
            ->assertNotified('Enlace enviado');

        Notification::assertSentTo($this->staff, PanelResetPassword::class);
        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'laura@example.com')->count());
    }

    public function test_the_owner_can_also_do_it_from_the_edit_page()
    {
        Notification::fake();
        $this->actingAs($this->owner);
        Filament::setTenant($this->coppers);

        Livewire::test(EditUser::class, ['record' => $this->staff->getRouteKey()])
            ->callAction('sendPasswordLink')
            ->assertNotified('Enlace enviado');

        Notification::assertSentTo($this->staff, PanelResetPassword::class);
    }

    public function test_the_owner_can_not_use_it_on_owners_or_on_himself()
    {
        Notification::fake();
        $otherOwner = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($this->owner);
        Filament::setTenant($this->coppers);

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('sendPasswordLink', $otherOwner)
            ->assertTableActionHidden('sendPasswordLink', $this->owner);

        $this->assertFalse(SendPasswordLinkAction::allowed($otherOwner));
        Notification::assertNothingSent();
    }

    public function test_a_staff_member_can_not_reset_anyone_elses_password()
    {
        Notification::fake();
        $colleague = User::factory()->create(['role' => UserRole::Staff]);
        $colleague->stores()->attach($this->g5);

        $this->actingAs($this->staff);
        Filament::setTenant($this->g5);

        // El recurso Usuarios no existe para el staff: ni la lista ni la edición.
        Livewire::test(ListUsers::class)->assertForbidden();
        Livewire::test(EditUser::class, ['record' => $colleague->getRouteKey()])->assertForbidden();
        $this->get('/admin/g5/users')->assertForbidden();

        // Y aunque llegara a la acción, la regla se vuelve a comprobar al ejecutarla.
        $this->assertFalse(SendPasswordLinkAction::allowed($colleague));
        $this->assertFalse(SendPasswordLinkAction::allowed($this->owner));
        $this->assertFalse(SendPasswordLinkAction::allowed($this->staff));

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
    }

    public function test_the_login_page_offers_the_recovery_link()
    {
        $this->get('/admin/login')->assertOk()->assertSee('password-reset/request', false);
        $this->get('/admin/password-reset/request')->assertOk();
    }
}
