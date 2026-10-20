<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveStore;
use App\Models\Customer;
use App\Models\Store;
use App\Notifications\CustomerResetPassword;
use App\Support\TrustedProxies;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Demo con un túnel HTTPS (ngrok) delante de la app: TRUSTED_PROXIES decide si se cree el X-Forwarded-Proto y
 * STORE_DOMAIN_ALIASES asocia el dominio del túnel a una tienda sin quitarle su dominio real.
 */
class TunnelProxyTest extends TestCase
{
    use RefreshDatabase;

    private const TUNNEL = 'mi-demo.ngrok-free.app';

    private Store $coppers;

    private Store $g5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);

        config(['shop.domain_aliases' => self::TUNNEL.'=coppers']);

        Route::get('/__ip', fn (Request $request) => response()->json(['ip' => $request->ip(), 'secure' => $request->secure(), 'scheme' => $request->getScheme()]));
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    /** Lo que hace TRUSTED_PROXIES en bootstrap/app.php. */
    private function trust(?string $value): void
    {
        TrustProxies::flushState();
        TrustedProxies::configure(new Middleware, $value);
    }

    private function viaTunnel(string $path = '/')
    {
        return $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', 'X-Forwarded-For' => '203.0.113.7'])
            ->get('http://'.self::TUNNEL.$path);
    }

    // ---- TRUSTED_PROXIES -----------------------------------------------------------------------------

    public function test_the_variable_is_read_safely()
    {
        $this->assertNull(TrustedProxies::parse(null));
        $this->assertNull(TrustedProxies::parse(''));
        $this->assertNull(TrustedProxies::parse('  '));
        $this->assertNull(TrustedProxies::parse(' , '));
        $this->assertSame('*', TrustedProxies::parse('*'));
        $this->assertSame('*', TrustedProxies::parse(' ** '));
        $this->assertSame(['10.0.0.5'], TrustedProxies::parse('10.0.0.5'));
        $this->assertSame(['10.0.0.5', '172.16.0.0/12'], TrustedProxies::parse('10.0.0.5, 172.16.0.0/12'));
    }

    public function test_by_default_no_proxy_is_trusted_so_a_forged_forwarded_proto_changes_nothing()
    {
        $this->trust(null);

        $this->viaTunnel('/__ip')->assertOk()->assertJson(['secure' => false, 'scheme' => 'http', 'ip' => '127.0.0.1']);
    }

    public function test_with_the_tunnel_trusted_the_scheme_is_https_and_the_ip_is_the_clients()
    {
        $this->trust('*');

        $this->viaTunnel('/__ip')->assertOk()->assertJson(['secure' => true, 'scheme' => 'https', 'ip' => '203.0.113.7']);
    }

    public function test_a_list_of_proxies_trusts_only_those()
    {
        $this->trust('10.9.9.9');
        $this->viaTunnel('/__ip')->assertJson(['secure' => false, 'ip' => '127.0.0.1']);

        $this->trust('127.0.0.1');
        $this->viaTunnel('/__ip')->assertJson(['secure' => true, 'ip' => '203.0.113.7']);
    }

    public function test_the_forwarded_host_is_never_trusted_so_it_can_not_switch_the_store()
    {
        $this->trust('*');

        $this->withHeaders(['X-Forwarded-Host' => 'g5.localhost', 'X-Forwarded-Proto' => 'https'])
            ->get('http://coppers.localhost/')
            ->assertInertia(fn (Assert $page) => $page->where('store.slug', 'coppers'));
    }

    // ---- Dominio del túnel ---------------------------------------------------------------------------

    public function test_the_tunnel_domain_resolves_to_coppers_and_the_local_domain_keeps_working()
    {
        $this->trust('*');

        $this->viaTunnel()->assertOk()->assertInertia(fn (Assert $page) => $page->where('store.slug', 'coppers')->where('store.theme', 'coppers'));
        $this->get('http://coppers.localhost/')->assertInertia(fn (Assert $page) => $page->where('store.slug', 'coppers'));
        $this->get('http://g5.localhost/')->assertInertia(fn (Assert $page) => $page->where('store.slug', 'g5'));

        $this->assertSame('coppers.localhost', $this->coppers->fresh()->domain);
    }

    public function test_without_the_alias_the_tunnel_domain_is_just_unknown()
    {
        config(['shop.domain_aliases' => '']);

        $this->viaTunnel()->assertNotFound();
    }

    public function test_an_alias_to_an_unknown_or_inactive_store_is_not_found_and_other_domains_stay_unknown()
    {
        config(['shop.domain_aliases' => self::TUNNEL.'=no-existe,otro.ngrok-free.app=g5']);
        $this->viaTunnel()->assertNotFound();

        $this->g5->update(['is_active' => false]);
        $this->get('http://otro.ngrok-free.app/')->assertNotFound();

        $this->get('http://desconocido.example.com/')->assertNotFound();
    }

    public function test_aliases_are_parsed_ignoring_spaces_case_and_empty_pairs()
    {
        config(['shop.domain_aliases' => ' Mi-Demo.NGROK-free.app = coppers , ,sin-slug=, =sin-dominio,otro.dev=g5 ']);

        $this->assertSame(['mi-demo.ngrok-free.app' => 'coppers', 'otro.dev' => 'g5'], ResolveStore::aliases());
        $this->assertSame($this->coppers->id, ResolveStore::forHost('MI-DEMO.ngrok-free.app')?->id);
    }

    // ---- Las direcciones que se generan --------------------------------------------------------------

    public function test_the_google_redirect_uri_is_https_on_the_tunnel_domain()
    {
        $this->trust('*');
        config(['services.google.client_id' => 'id-de-prueba', 'services.google.client_secret' => 'secreto-de-prueba']);

        $location = $this->viaTunnel('/auth/google')->assertRedirect()->headers->get('Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('https://'.self::TUNNEL.'/auth/google/callback', $query['redirect_uri']);
    }

    public function test_without_trusting_the_proxy_the_google_redirect_uri_would_be_http()
    {
        $this->trust(null);
        config(['services.google.client_id' => 'id-de-prueba', 'services.google.client_secret' => 'secreto-de-prueba']);

        $location = $this->viaTunnel('/auth/google')->assertRedirect()->headers->get('Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('http://'.self::TUNNEL.'/auth/google/callback', $query['redirect_uri']);
    }

    public function test_the_customer_reset_link_is_https_on_the_tunnel_domain()
    {
        $this->trust('*');
        Notification::fake();
        $customer = Customer::factory()->create(['email' => 'ana@example.com']);

        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443'])
            ->post('http://'.self::TUNNEL.'/forgot-password', ['email' => 'ana@example.com'])
            ->assertSessionHas('status');

        Notification::assertSentTo($customer, CustomerResetPassword::class, function (CustomerResetPassword $notification) use ($customer) {
            $url = $notification->toMail($customer)->viewData['url'];

            return $notification->baseUrl === 'https://'.self::TUNNEL
                && str_starts_with($url, 'https://'.self::TUNNEL.'/reset-password/')
                && $notification->theme === 'coppers';
        });
    }

    public function test_pages_generated_behind_the_tunnel_use_https_links()
    {
        $this->trust('*');

        $this->viaTunnel('/login')->assertOk();

        $this->assertSame('https://'.self::TUNNEL.'/login', route('login'));
    }
}
