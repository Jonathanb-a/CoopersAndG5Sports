<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\StorePages\Pages\CreateStorePage;
use App\Filament\Resources\StorePages\Pages\EditStorePage;
use App\Http\Middleware\ResolveStore;
use App\Models\Store;
use App\Models\StorePage;
use App\Models\User;
use App\Support\SafeHtml;
use Database\Seeders\StorePagesSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Páginas de contenido y legales (/pagina/{slug}), su pie de página, el seeder de borradores y las páginas de error. */
class StorePagesTest extends TestCase
{
    use RefreshDatabase;

    private const COPPERS = 'http://coppers.localhost';

    private const G5 = 'http://g5.localhost';

    private Store $coppers;

    private Store $g5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers', 'whatsapp_phone' => '7551112222', 'pickup_address' => 'Av. Principal 123', 'pickup_hours' => 'Lunes a sábado 10 a 19 h']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);
    }

    private function page(Store $store, string $slug, bool $published = true, string $body = '<p>Hola</p>', array $extra = []): StorePage
    {
        return StorePage::create(['store_id' => $store->id, 'slug' => $slug, 'title' => StorePage::PAGES[$slug], 'body' => $body, 'is_published' => $published, ...$extra]);
    }

    private function panelAs(UserRole $role, Store $store): User
    {
        $user = User::factory()->create(['role' => $role]);

        if ($role === UserRole::Staff) {
            $user->stores()->attach($store->id);
        }

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($store);

        return $user;
    }

    // ---- Página pública ---------------------------------------------------------------------------

    public function test_a_published_page_is_seen_with_its_title_and_description()
    {
        $this->page($this->coppers, 'contacto', body: '<h2>WhatsApp</h2><p>Escríbenos al 755 111 2222.</p>');

        $response = $this->get(self::COPPERS.'/pagina/contacto')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('store-page')
            ->where('page.title', 'Contacto')
            ->where('page.html', '<h2>WhatsApp</h2><p>Escríbenos al 755 111 2222.</p>')
            ->where('meta.title', "Contacto · Copper's"));

        // SEO en el HTML del servidor: título con la tienda y descripción propia.
        $html = $response->getContent();
        $this->assertStringContainsString('<title inertia>Contacto · Copper&#039;s</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="WhatsApp Escríbenos al 755 111 2222.">', $html);
    }

    public function test_an_unpublished_page_is_404()
    {
        $this->page($this->coppers, 'aviso-de-privacidad', published: false);

        $this->get(self::COPPERS.'/pagina/aviso-de-privacidad')->assertNotFound();
        $this->get(self::COPPERS.'/pagina/no-existe')->assertNotFound();
    }

    public function test_a_page_of_the_other_store_is_404()
    {
        $this->page($this->g5, 'contacto');

        $this->get(self::G5.'/pagina/contacto')->assertOk();
        $this->get(self::COPPERS.'/pagina/contacto')->assertNotFound();
    }

    public function test_the_internal_note_is_never_shown_to_the_public()
    {
        $this->page($this->coppers, 'terminos-y-condiciones', extra: ['internal_note' => 'BORRADOR: revisar con el cliente antes de publicar']);

        $response = $this->get(self::COPPERS.'/pagina/terminos-y-condiciones')->assertOk();

        $this->assertStringNotContainsString('BORRADOR', $response->getContent());
    }

    // ---- HTML saneado -----------------------------------------------------------------------------

    public function test_html_is_sanitized_when_saving()
    {
        $page = $this->page($this->coppers, 'contacto', body: '<h2 onclick="alert(1)" style="color:red">Hola</h2><script>alert("x")</script>'
            .'<p>Texto <a href="javascript:alert(1)">malo</a> y <a href="https://wa.me/527551112222" target="_blank">bueno</a></p>'
            .'<iframe src="https://evil.test"></iframe><img src="x" onerror="alert(1)"><ul><li><strong>uno</strong></li></ul>');

        $body = $page->fresh()->body;

        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('alert', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringNotContainsString('style=', $body);
        $this->assertStringNotContainsString('javascript:', $body);
        $this->assertStringNotContainsString('<iframe', $body);
        $this->assertStringNotContainsString('<img', $body);
        $this->assertStringContainsString('<h2>Hola</h2>', $body);
        $this->assertStringContainsString('href="https://wa.me/527551112222"', $body);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $body);
        $this->assertStringContainsString('<ul><li><strong>uno</strong></li></ul>', $body);
    }

    public function test_html_is_sanitized_again_when_shown_even_if_it_skipped_the_model()
    {
        $page = $this->page($this->coppers, 'contacto');
        DB::table('store_pages')->where('id', $page->id)->update(['body' => '<p>Hola</p><script>alert(1)</script><p onmouseover="x()">Adiós</p>']);

        $this->get(self::COPPERS.'/pagina/contacto')
            ->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia->where('page.html', '<p>Hola</p><p>Adiós</p>'));
    }

    public function test_safe_html_drops_dangerous_markup()
    {
        $this->assertSame('<p>ok</p>', SafeHtml::clean('<p>ok</p><script>bad()</script><style>p{}</style>'));
        $this->assertSame('', SafeHtml::clean(null));
        $this->assertSame('Hola mundo', SafeHtml::excerpt('<h2>Hola</h2><p>mundo</p>'));
    }

    // ---- Panel: quién publica ---------------------------------------------------------------------

    public function test_staff_can_edit_content_but_not_publish()
    {
        $this->panelAs(UserRole::Staff, $this->coppers);

        Livewire::test(CreateStorePage::class)
            ->assertFormFieldHidden('is_published')
            ->fillForm(['slug' => 'contacto', 'title' => 'Contacto', 'body' => '<p>Hola</p>'])
            ->set('data.is_published', true)
            ->call('create')
            ->assertHasNoFormErrors();

        $created = StorePage::withoutGlobalScopes()->where('slug', 'contacto')->sole();
        $this->assertFalse($created->is_published);
        $this->assertSame($this->coppers->id, $created->store_id);

        // Una ya publicada sigue publicada aunque el staff la edite e intente despublicarla.
        $published = $this->page($this->coppers, 'quienes-somos');

        Livewire::test(EditStorePage::class, ['record' => $published->getRouteKey()])
            ->fillForm(['title' => 'Nosotros'])
            ->set('data.is_published', false)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nosotros', $published->fresh()->title);
        $this->assertTrue($published->fresh()->is_published);
    }

    public function test_the_owner_can_publish_and_unpublish()
    {
        $this->panelAs(UserRole::Owner, $this->coppers);
        $page = $this->page($this->coppers, 'contacto', published: false);

        Livewire::test(EditStorePage::class, ['record' => $page->getRouteKey()])
            ->assertFormFieldVisible('is_published')
            ->fillForm(['is_published' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($page->fresh()->is_published);
    }

    public function test_staff_of_one_store_can_not_open_the_pages_of_the_other()
    {
        $page = $this->page($this->g5, 'contacto');
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $staff->stores()->attach($this->coppers->id);

        $this->actingAs($staff)->get("/admin/coppers/store-pages/{$page->id}/edit")->assertNotFound();
        $this->actingAs($staff)->get("/admin/g5/store-pages/{$page->id}/edit")->assertNotFound();
    }

    // ---- Pie de página y consentimiento -----------------------------------------------------------

    public function test_the_footer_only_links_published_pages_of_this_store_in_order()
    {
        $this->page($this->coppers, 'terminos-y-condiciones');
        $this->page($this->coppers, 'contacto');
        $this->page($this->coppers, 'aviso-de-privacidad', published: false);
        $this->page($this->g5, 'preguntas-frecuentes');

        $this->get(self::COPPERS.'/')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('footer.pages', [
                ['slug' => 'contacto', 'title' => 'Contacto', 'url' => '/pagina/contacto'],
                ['slug' => 'terminos-y-condiciones', 'title' => 'Términos y condiciones', 'url' => '/pagina/terminos-y-condiciones'],
            ])
            ->where('footer.address', 'Av. Principal 123')
            ->where('footer.hours', 'Lunes a sábado 10 a 19 h'));
    }

    public function test_register_and_checkout_receive_the_legal_links()
    {
        $this->page($this->coppers, 'aviso-de-privacidad');

        $this->get(self::COPPERS.'/register')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('auth/register')
            ->where('footer.pages.0.slug', 'aviso-de-privacidad'));
    }

    // ---- Seeder de borradores ---------------------------------------------------------------------

    public function test_the_seeder_creates_unpublished_drafts_with_pending_brackets()
    {
        $this->seed(StorePagesSeeder::class);

        foreach ([$this->coppers, $this->g5] as $store) {
            $pages = StorePage::withoutGlobalScopes()->where('store_id', $store->id)->get()->keyBy('slug');

            $this->assertEqualsCanonicalizing(array_keys(StorePage::PAGES), $pages->keys()->all());

            foreach (['aviso-de-privacidad', 'terminos-y-condiciones', 'cambios-y-devoluciones', 'envios-y-entregas', 'preguntas-frecuentes'] as $slug) {
                $this->assertFalse($pages[$slug]->is_published, "{$store->slug} {$slug}");
                $this->assertSame(StorePagesSeeder::DRAFT_NOTE, $pages[$slug]->internal_note);
            }

            // Datos pendientes del dueño, entre corchetes.
            $this->assertStringContainsString('[RAZÓN SOCIAL Y DOMICILIO]', $pages['aviso-de-privacidad']->body);
            $this->assertStringContainsString('[DÍAS PARA CAMBIOS Y DEVOLUCIONES]', $pages['cambios-y-devoluciones']->body);

            // Estructura del aviso y de los términos.
            foreach (['Responsable', 'Datos que recabamos', 'Finalidades', 'Con quién compartimos', 'Derechos ARCO', 'Cambios a este aviso'] as $heading) {
                $this->assertStringContainsString($heading, $pages['aviso-de-privacidad']->body);
            }
            foreach (['Apartado y pago', 'Vigencia del apartado', 'Cancelaciones', 'Entrega y recogida', 'Cambios y devoluciones'] as $heading) {
                $this->assertStringContainsString($heading, $pages['terminos-y-condiciones']->body);
            }

            // Datos reales del sistema: nombre y plazos de apartado.
            $this->assertStringContainsString(e($store->name), $pages['terminos-y-condiciones']->body);
            $this->assertStringContainsString(config('shop.reserve_hours').' horas', $pages['terminos-y-condiciones']->body);
        }

        // Copper's tiene WhatsApp, dirección y horario: su Contacto sale sin pendientes y publicado.
        $coppersContact = StorePage::withoutGlobalScopes()->where('store_id', $this->coppers->id)->where('slug', 'contacto')->sole();
        $this->assertTrue($coppersContact->is_published);
        $this->assertStringContainsString('7551112222', $coppersContact->body);

        // G5 no los tiene: queda en borrador con sus corchetes.
        $g5Contact = StorePage::withoutGlobalScopes()->where('store_id', $this->g5->id)->where('slug', 'contacto')->sole();
        $this->assertFalse($g5Contact->is_published);
        $this->assertStringContainsString('[NÚMERO DE WHATSAPP]', $g5Contact->body);
    }

    public function test_the_seeder_never_overwrites_an_edited_page()
    {
        $this->page($this->coppers, 'contacto', body: '<p>Texto del dueño</p>');

        $this->seed(StorePagesSeeder::class);

        $this->assertSame('<p>Texto del dueño</p>', StorePage::withoutGlobalScopes()->where('store_id', $this->coppers->id)->where('slug', 'contacto')->sole()->body);
    }

    // ---- Páginas de error con el tema -------------------------------------------------------------

    public function test_a_missing_page_is_a_404_with_the_theme_of_its_store()
    {
        foreach ([self::COPPERS => 'coppers', self::G5 => 'g5'] as $host => $theme) {
            $this->get("{$host}/esto-no-existe")
                ->assertNotFound()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('error')
                    ->where('status', 404)
                    ->where('title', 'No encontramos esta página')
                    ->where('store.theme', $theme));
        }
    }

    public function test_419_500_and_503_render_with_the_store_theme()
    {
        config(['app.debug' => false]);

        Route::middleware(['web', ResolveStore::class])->group(function () {
            Route::get('_prueba/500', fn () => throw new RuntimeException('falla'));
            Route::get('_prueba/419', fn () => throw new TokenMismatchException);
            Route::get('_prueba/503', fn () => throw new HttpException(503));
        });

        foreach ([500 => 'Algo salió mal', 419 => 'Tu sesión venció', 503 => 'Volvemos en un momento'] as $status => $title) {
            $this->get(self::G5."/_prueba/{$status}")
                ->assertStatus($status)
                ->assertInertia(fn (Assert $page) => $page
                    ->component('error')
                    ->where('status', $status)
                    ->where('title', $title)
                    ->where('store.theme', 'g5'));
        }
    }

    public function test_errors_outside_a_store_or_as_json_keep_the_laravel_response()
    {
        $this->getJson(self::COPPERS.'/pagina/no-existe')->assertNotFound()->assertJsonStructure(['message']);

        $response = $this->get('http://localhost/esto-no-existe')->assertNotFound();
        $this->assertStringNotContainsString('"component":"error"', $response->getContent());
    }
}
