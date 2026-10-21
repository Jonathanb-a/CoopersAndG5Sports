<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;
use Tighten\Ziggy\BladeRouteGenerator;

/**
 * La demo con túnel TAL COMO SE USA: la aplicación completa arranca con un .env como el real (APP_URL https,
 * TRUSTED_PROXIES, alias del dominio del túnel) y recibe la petición que reenvía ngrok (Host del túnel +
 * X-Forwarded-Proto https). Cada prueba arma su propia aplicación con esos valores en el entorno.
 *
 * TunnelProxyTest prueba las piezas con la aplicación de pruebas ya arrancada; esto prueba que el arranque las
 * encuentra: TRUSTED_PROXIES se leía en bootstrap/app.php, donde el .env todavía no está cargado (env() daba null),
 * así que en el servidor real nunca se confiaba en el proxy aunque las pruebas de las piezas pasaran.
 */
class TunnelHttpsTest extends TestCase
{
    private const TUNNEL = 'goldfish-earthy-animate.ngrok-free.dev';

    /** @var array<string, array{0: string|false, 1: mixed, 2: mixed}> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => [$process, $env, $server]) {
            $process === false ? putenv($key) : putenv("{$key}={$process}");
            $env === null ? $this->forget($_ENV, $key) : $_ENV[$key] = $env;
            $server === null ? $this->forget($_SERVER, $key) : $_SERVER[$key] = $server;
        }

        parent::tearDown();
    }

    private function forget(array &$bag, string $key): void
    {
        unset($bag[$key]);
    }

    /** Arranca una aplicación nueva con estas variables de entorno (como si estuvieran en el .env) y una tienda. */
    private function bootWith(array $env): void
    {
        foreach ($env as $key => $value) {
            $this->savedEnv[$key] ??= [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $this->refreshApplication();

        // Ziggy imprime su configuración (con la url) solo la primera vez por proceso: se reinicia para que no dependa
        // de qué prueba corrió antes.
        BladeRouteGenerator::$generated = false;

        Artisan::call('migrate', ['--force' => true]);
        Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers']);
        Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);

        Route::get('/__ip', fn (Request $request) => response()->json(['ip' => $request->ip(), 'secure' => $request->secure()]));
    }

    /** El .env de la demo, con una variable cambiada o quitada a gusto de cada prueba. */
    private function demoEnv(array $override = []): array
    {
        return array_merge([
            'APP_URL' => 'https://'.self::TUNNEL,
            'TRUSTED_PROXIES' => '*',
            'STORE_DOMAIN_ALIASES' => self::TUNNEL.'=coppers',
            'SESSION_SECURE_COOKIE' => 'true',
            'GOOGLE_CLIENT_ID' => 'id-de-prueba',
            'GOOGLE_CLIENT_SECRET' => 'secreto-de-prueba',
        ], $override);
    }

    private function viaNgrok(string $path = '/')
    {
        // Los encabezados van en la petición (no con withHeaders, que se quedaría en las siguientes de la prueba).
        return $this->get('http://'.self::TUNNEL.$path, ['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '1.2.3.4']);
    }

    private function insecureLinks(string $html): int
    {
        return preg_match_all('/(?:href|src)="http:\/\//', $html);
    }

    // ---- La petición de la demo ----------------------------------------------------------------------

    public function test_the_env_value_is_there_when_the_proxies_are_applied()
    {
        $this->bootWith($this->demoEnv());

        $this->assertSame('*', config('app.trusted_proxies'));

        $this->viaNgrok('/__ip')->assertJson(['secure' => true, 'ip' => '1.2.3.4']);
    }

    public function test_the_tunnel_request_gets_the_store_with_https_assets_and_ziggy()
    {
        $this->bootWith($this->demoEnv());

        $response = $this->viaNgrok()->assertOk()->assertInertia(fn (Assert $page) => $page->where('store.slug', 'coppers'));
        $html = $response->getContent();

        $this->assertSame(0, $this->insecureLinks($html), 'Quedó algún href o src con http:// (contenido mixto detrás de https).');
        $this->assertMatchesRegularExpression('/(?:href|src)="https:\/\/'.preg_quote(self::TUNNEL, '/').'\/build\/assets\/[^"]+"/', $html);
        $this->assertStringContainsString('"url":"https:\/\/'.self::TUNNEL, $html);
    }

    public function test_the_session_cookie_travels_only_over_https()
    {
        $this->bootWith($this->demoEnv());

        $cookies = $this->viaNgrok('/login')->assertOk()->headers->getCookies();
        $session = collect($cookies)->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($session);
        $this->assertTrue($session->isSecure());
        $this->assertNull($session->getDomain());
    }

    public function test_the_google_redirect_uri_is_https_on_the_tunnel_domain()
    {
        $this->bootWith($this->demoEnv());

        $location = $this->viaNgrok('/auth/google')->assertRedirect()->headers->get('Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('https://'.self::TUNNEL.'/auth/google/callback', $query['redirect_uri']);
    }

    // ---- Cada mecanismo por separado -----------------------------------------------------------------

    public function test_the_safety_net_keeps_https_even_if_the_proxy_sends_no_forwarded_proto()
    {
        $this->bootWith($this->demoEnv(['TRUSTED_PROXIES' => '']));

        $html = $this->get('http://'.self::TUNNEL.'/')->assertOk()->getContent();

        $this->assertSame(0, $this->insecureLinks($html));
        $this->assertStringContainsString('"url":"https:\/\/'.self::TUNNEL, $html);
    }

    public function test_trusting_the_proxy_alone_is_enough_when_app_url_is_http()
    {
        $this->bootWith($this->demoEnv(['APP_URL' => 'http://localhost']));

        $this->assertSame(0, $this->insecureLinks($this->viaNgrok()->assertOk()->getContent()));

        // Sin X-Forwarded-Proto no hay por qué suponer https: la red de seguridad no se activa con APP_URL http.
        $this->assertGreaterThan(0, $this->insecureLinks($this->get('http://'.self::TUNNEL.'/')->assertOk()->getContent()));
    }

    public function test_by_default_nothing_is_trusted_and_a_forged_forwarded_proto_changes_nothing()
    {
        $this->bootWith($this->demoEnv(['APP_URL' => 'http://localhost', 'TRUSTED_PROXIES' => '']));

        $this->viaNgrok('/__ip')->assertJson(['secure' => false, 'ip' => '127.0.0.1']);
        $this->assertGreaterThan(0, $this->insecureLinks($this->viaNgrok()->assertOk()->getContent()));
    }

    public function test_a_list_of_proxies_that_is_not_the_caller_is_ignored()
    {
        $this->bootWith($this->demoEnv(['APP_URL' => 'http://localhost', 'TRUSTED_PROXIES' => '10.9.9.9']));

        $this->viaNgrok('/__ip')->assertJson(['secure' => false, 'ip' => '127.0.0.1']);
    }

    public function test_the_other_store_and_the_local_domains_are_untouched_by_the_tunnel_settings()
    {
        $this->bootWith($this->demoEnv());

        $this->get('http://g5.localhost/')->assertInertia(fn (Assert $page) => $page->where('store.slug', 'g5'));
        $this->get('http://coppers.localhost/')->assertInertia(fn (Assert $page) => $page->where('store.slug', 'coppers'));
    }
}
