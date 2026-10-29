<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\StorePage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** app:setup-produccion y el DatabaseSeeder en producción: solo owner y tiendas, sin datos de demostración. */
class SetupProductionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shop.admin_email' => 'dueno@coppers.mx',
            'shop.admin_password' => 'una-clave-larga-123',
            'shop.stores.coppers.domain' => 'coppers.mx',
            'shop.stores.g5.domain' => 'sportsg5.mx',
        ]);
    }

    public function test_it_creates_only_the_owner_and_both_stores_with_their_domains()
    {
        $this->artisan('app:setup-produccion')->assertSuccessful();

        $this->assertSame(1, User::count());
        $owner = User::first();
        $this->assertSame('dueno@coppers.mx', $owner->email);
        $this->assertSame(UserRole::Owner, $owner->role);
        $this->assertTrue(Hash::check('una-clave-larga-123', $owner->password));

        $stores = Store::orderBy('slug')->get();
        $this->assertSame(['coppers', 'g5'], $stores->pluck('slug')->all());
        $this->assertSame(['coppers.mx', 'sportsg5.mx'], $stores->pluck('domain')->all());
        $this->assertSame(['coppers', 'g5'], $stores->pluck('theme')->all());
        $this->assertTrue($stores->every->is_active);

        $this->assertSame(0, Product::withoutGlobalScopes()->withTrashed()->count());
        $this->assertSame(0, Category::withoutGlobalScopes()->count());
        $this->assertSame(0, Brand::count());
    }

    public function test_running_it_again_does_not_duplicate_and_keeps_the_owner_password_and_store_data()
    {
        $this->artisan('app:setup-produccion')->assertSuccessful();
        Store::where('slug', 'g5')->update(['name' => 'G5 Zihua', 'whatsapp_phone' => '7550000000', 'is_active' => false]);
        User::first()->update(['password' => 'otra-clave-del-panel']);

        config(['shop.admin_password' => 'clave-distinta-456', 'shop.stores.g5.domain' => 'tienda.sportsg5.mx']);
        $this->artisan('app:setup-produccion')->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertTrue(Hash::check('otra-clave-del-panel', User::first()->password));
        $this->assertSame(2, Store::count());

        $g5 = Store::where('slug', 'g5')->first();
        $this->assertSame('tienda.sportsg5.mx', $g5->domain);
        $this->assertSame('G5 Zihua', $g5->name);
        $this->assertSame('7550000000', $g5->whatsapp_phone);
        $this->assertFalse($g5->is_active);
    }

    public function test_an_existing_user_with_the_admin_email_becomes_owner()
    {
        User::factory()->create(['email' => 'dueno@coppers.mx', 'role' => UserRole::Staff]);

        $this->artisan('app:setup-produccion')->assertSuccessful();

        $this->assertSame(1, User::count());
        $this->assertSame(UserRole::Owner, User::first()->role);
    }

    public function test_it_fails_without_saving_when_the_password_is_shorter_than_12_characters()
    {
        config(['shop.admin_password' => 'corta-12345']); // 11 caracteres

        $this->artisan('app:setup-produccion')
            ->expectsOutputToContain('ADMIN_PASSWORD debe tener al menos 12 caracteres.')
            ->assertFailed();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Store::count());
    }

    public function test_it_fails_without_saving_when_the_email_or_a_domain_is_missing()
    {
        config(['shop.admin_email' => null, 'shop.stores.g5.domain' => null]);

        $this->artisan('app:setup-produccion')
            ->expectsOutputToContain('Define ADMIN_EMAIL con un correo válido.')
            ->expectsOutputToContain('Define G5_DOMAIN con el dominio de Sports G5.')
            ->assertFailed();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Store::count());
    }

    public function test_it_fails_when_both_stores_share_a_domain()
    {
        config(['shop.stores.g5.domain' => 'COPPERS.mx']);

        $this->artisan('app:setup-produccion')
            ->expectsOutputToContain('Cada tienda necesita un dominio distinto.')
            ->assertFailed();

        $this->assertSame(0, Store::count());
    }

    public function test_the_seeder_in_production_creates_no_staff_or_demo_products()
    {
        $this->app['env'] = 'production';

        // Directo, sin db:seed: en producción ese comando pide confirmación.
        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();

        $this->assertSame(['dueno@coppers.mx'], User::pluck('email')->all());
        $this->assertSame(['coppers.mx', 'sportsg5.mx'], Store::orderBy('slug')->pluck('domain')->all());
        $this->assertSame(0, Product::withoutGlobalScopes()->withTrashed()->count());
        $this->assertSame(0, Category::withoutGlobalScopes()->count());
        $this->assertSame(0, Brand::count());
        $this->assertGreaterThan(0, StorePage::withoutGlobalScopes()->count());
    }

    public function test_the_seeder_outside_production_keeps_staff_and_demo_products_with_local_domains()
    {
        config(['shop.stores.coppers.domain' => null, 'shop.stores.g5.domain' => null]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(['coppers.localhost', 'g5.localhost'], Store::orderBy('slug')->pluck('domain')->all());
        $this->assertSame(2, User::where('role', UserRole::Staff)->count());
        $this->assertSame(4, Product::withoutGlobalScopes()->count());
    }
}
