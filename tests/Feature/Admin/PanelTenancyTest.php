<?php

namespace Tests\Feature\Admin;

use App\Enums\SizeType;
use App\Enums\UserRole;
use App\Filament\Pages\Tenancy\EditStoreProfile;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Concerns\BelongsToStore;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class PanelTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Store $coppers;

    private Store $g5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => UserRole::Owner]);
    }

    private function staffOf(Store ...$stores): User
    {
        $user = User::factory()->create(['role' => UserRole::Staff]);
        $user->stores()->attach(array_map(fn (Store $store) => $store->id, $stores));

        return $user;
    }

    public function test_owner_can_enter_every_store()
    {
        $this->actingAs($this->owner());

        $this->get('/admin/coppers')->assertOk();
        $this->get('/admin/g5')->assertOk();
    }

    public function test_g5_staff_enters_g5_and_gets_404_on_coppers()
    {
        $this->actingAs($this->staffOf($this->g5));

        $this->get('/admin/g5')->assertOk();
        $this->get('/admin/coppers')->assertNotFound();
    }

    public function test_staff_cannot_see_users_or_store_profile()
    {
        $this->actingAs($this->staffOf($this->g5));

        $this->get('/admin/g5/users')->assertForbidden();
        $this->get('/admin/g5/users/create')->assertForbidden();
        $this->get('/admin/g5/profile')->assertNotFound();

        $this->get('/admin/g5')
            ->assertDontSee('Usuarios')
            ->assertDontSee('Datos de la tienda');
    }

    public function test_owner_can_see_users_and_store_profile()
    {
        $this->actingAs($this->owner());

        $this->get('/admin/coppers/users')->assertOk();
        $this->get('/admin/coppers/profile')->assertOk();

        $this->get('/admin/coppers')
            ->assertSee('Usuarios')
            ->assertSee('Datos de la tienda');
    }

    public function test_tenant_switcher_only_lists_allowed_active_stores()
    {
        $inactive = Store::create(['name' => 'Cerrada', 'slug' => 'cerrada', 'domain' => 'cerrada.localhost', 'is_active' => false]);
        $panel = Filament::getPanel('admin');

        $owner = $this->owner();
        $staff = $this->staffOf($this->g5, $inactive);

        $this->assertEqualsCanonicalizing(['coppers', 'g5'], $this->tenantSlugs($owner, $panel));
        $this->assertSame(['g5'], $this->tenantSlugs($staff, $panel));

        $this->actingAs($staff)->get('/admin/g5')
            ->assertSee('Sports G5')
            ->assertDontSee("Copper's")
            ->assertDontSee('Cerrada');

        $this->actingAs($owner)->get('/admin/cerrada')->assertNotFound();
    }

    public function test_owner_can_create_staff_with_stores()
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->coppers);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Nuevo Personal',
                'email' => 'nuevo@example.com',
                'password' => 'secreto123',
                'role' => UserRole::Staff,
                'stores' => [$this->g5->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'nuevo@example.com')->firstOrFail();

        $this->assertSame(UserRole::Staff, $user->role);
        $this->assertSame(['g5'], $user->stores->pluck('slug')->all());
        $this->assertTrue(Hash::check('secreto123', $user->password));
    }

    public function test_owner_can_edit_store_profile()
    {
        $this->actingAs($this->owner());
        Filament::setCurrentPanel('admin');
        Filament::setTenant($this->coppers);

        Livewire::test(EditStoreProfile::class)
            ->fillForm([
                'name' => "Copper's MX",
                'whatsapp_phone' => '5512345678',
                'telegram_chat_id' => '12345',
                'bank_info' => 'Banco X',
                'is_active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame("Copper's MX", $this->coppers->fresh()->name);
        $this->assertSame('5512345678', $this->coppers->fresh()->whatsapp_phone);
    }

    public function test_belongs_to_store_trait_does_not_interfere_with_the_panel()
    {
        foreach ([$this->coppers, $this->g5] as $store) {
            $category = Category::create(['store_id' => $store->id, 'name' => 'General', 'slug' => 'general', 'size_type' => SizeType::OneSize]);
            Product::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => "Producto {$store->slug}", 'slug' => 'producto', 'price' => 10]);
        }

        $this->actingAs($this->owner());

        $this->get('/admin/coppers')->assertOk();
        $this->get('/admin/g5')->assertOk();

        $this->assertFalse(app()->bound('currentStore'));

        // El scope de tenant de Filament sigue activo tras la petición en pruebas; se quita para
        // comprobar que BelongsToStore (scope "store") no filtra nada dentro del panel.
        $filamentScope = Filament::getPanel('admin')->getTenancyScopeName();
        $this->assertSame(2, Product::withoutGlobalScope($filamentScope)->count());
    }

    /** @return list<string> */
    private function tenantSlugs(User $user, Panel $panel): array
    {
        return $user->getTenants($panel)->pluck('slug')->all();
    }

    /**
     * Cada modelo con store_id debe usar BelongsToStore y estar cubierto en el panel: por un recurso de
     * Filament con tenant (Filament le agrega su scope) o por un filtro explícito justificado aquí.
     * Así un modelo nuevo con store_id no se cuela sin scope.
     */
    public function test_every_model_with_store_id_is_scoped_in_the_panel()
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);

        // modelo => dónde se filtra a mano por tienda (vacío: hoy todos tienen recurso con tenant).
        $explicitFilters = [];

        $tenantModels = collect($panel->getResources())
            ->filter(fn (string $resource) => $resource::isScopedToTenant())
            ->map(fn (string $resource) => $resource::getModel())
            ->all();

        $found = [];

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! is_subclass_of($class, Model::class)) {
                continue;
            }

            if (! Schema::hasColumn((new $class)->getTable(), 'store_id')) {
                continue;
            }

            $found[] = $class;

            $this->assertContains(BelongsToStore::class, class_uses_recursive($class), "{$class} tiene store_id pero no usa BelongsToStore.");
            $this->assertTrue(
                in_array($class, $tenantModels, true) || isset($explicitFilters[$class]),
                "{$class} tiene store_id pero ningún recurso del panel con tenant lo cubre ni hay un filtro explícito en esta prueba."
            );
        }

        // Los cinco modelos con tienda de hoy; si cambia la lista, que sea a propósito.
        $this->assertEqualsCanonicalizing(
            [Banner::class, Category::class, Coupon::class, Order::class, Product::class],
            $found,
        );
    }
}
