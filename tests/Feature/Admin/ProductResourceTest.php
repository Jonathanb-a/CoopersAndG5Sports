<?php

namespace Tests\Feature\Admin;

use App\Enums\SizeType;
use App\Enums\UserRole;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProductResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $coppers;

    private Store $g5;

    private Category $coppersCategory;

    private Category $g5Category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost']);

        $this->coppersCategory = $this->category($this->coppers, 'Camisas');
        $this->g5Category = $this->category($this->g5, 'Calzado');
    }

    private function category(Store $store, string $name): Category
    {
        return Category::create([
            'store_id' => $store->id,
            'name' => $name,
            'slug' => str($name)->slug(),
            'size_type' => SizeType::Clothing,
        ]);
    }

    private function product(Store $store, Category $category, string $name, string $color = 'Azul', array $extra = []): Product
    {
        return Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'color' => $color,
            'slug' => str("$name $color")->slug(),
            'price' => 100,
            ...$extra,
        ]);
    }

    private function panelAs(UserRole $role, Store $store, ?Store $staffStore = null): User
    {
        $user = User::factory()->create(['role' => $role]);

        if ($role === UserRole::Staff) {
            $user->stores()->attach(($staffStore ?? $store)->id);
        }

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        // En peticiones reales lo hace el middleware SetUpPanel; registra el scope y el observer del tenant.
        Filament::bootCurrentPanel();
        Filament::setTenant($store);

        return $user;
    }

    private function formData(Category $category, array $overrides = []): array
    {
        return [
            'name' => 'Tenis Predator',
            'color' => 'Negro',
            'category_id' => $category->id,
            'price' => 1999.5,
            'variants' => [
                ['size' => '26', 'sku' => 'TP-26', 'stock' => 5],
                ['size' => '27', 'sku' => 'TP-27', 'stock' => 0],
            ],
            ...$overrides,
        ];
    }

    public function test_product_created_in_g5_gets_the_g5_store_id()
    {
        $this->panelAs(UserRole::Owner, $this->g5);

        Livewire::test(CreateProduct::class)
            ->fillForm($this->formData($this->g5Category))
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::withoutGlobalScopes()->where('name', 'Tenis Predator')->firstOrFail();

        $this->assertSame($this->g5->id, $product->store_id);
        $this->assertSame('tenis-predator-negro', $product->slug);
        $this->assertSame(['26' => 5, '27' => 0], $product->variants->pluck('stock', 'size')->all());
    }

    public function test_a_category_from_another_store_is_rejected()
    {
        $this->panelAs(UserRole::Owner, $this->g5);

        Livewire::test(CreateProduct::class)
            ->fillForm($this->formData($this->coppersCategory))
            ->call('create')
            ->assertHasFormErrors(['category_id']);

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }

    public function test_staff_does_not_see_nor_submit_cost()
    {
        $this->panelAs(UserRole::Staff, $this->g5);

        Livewire::test(CreateProduct::class)
            ->assertFormFieldHidden('cost')
            ->fillForm($this->formData($this->g5Category))
            ->set('data.cost', '99.00')
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::withoutGlobalScopes()->where('name', 'Tenis Predator')->firstOrFail();
        $this->assertNull($product->cost);
    }

    public function test_staff_cannot_change_an_existing_cost_and_owner_can()
    {
        $product = $this->product($this->g5, $this->g5Category, 'Jersey', 'Rojo', ['cost' => 50]);
        $product->variants()->create(['size' => 'M', 'stock' => 1]);

        $this->panelAs(UserRole::Staff, $this->g5);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldHidden('cost')
            ->set('data.cost', '1.00')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('50.00', $product->fresh()->cost);

        $this->panelAs(UserRole::Owner, $this->g5);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormFieldVisible('cost')
            ->fillForm(['cost' => 60])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('60.00', $product->fresh()->cost);
    }

    public function test_same_slug_is_allowed_across_stores_but_not_within_one_store()
    {
        // Via formulario
        $this->panelAs(UserRole::Owner, $this->coppers);
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formData($this->coppersCategory, ['name' => 'Camisa', 'color' => 'Azul']))
            ->call('create')
            ->assertHasNoFormErrors();

        Filament::setTenant($this->g5);
        Livewire::test(CreateProduct::class)
            ->fillForm($this->formData($this->g5Category, ['name' => 'Camisa', 'color' => 'Azul']))
            ->call('create')
            ->assertHasNoFormErrors();

        Livewire::test(CreateProduct::class)
            ->fillForm($this->formData($this->g5Category, ['name' => 'Camisa', 'color' => 'Azul']))
            ->call('create')
            ->assertHasFormErrors(['slug' => 'unique']);

        $this->assertSame(
            [$this->coppers->id, $this->g5->id],
            Product::withoutGlobalScopes()->where('slug', 'camisa-azul')->orderBy('store_id')->pluck('store_id')->all(),
        );

        // A nivel de base de datos
        $this->expectException(QueryException::class);
        $this->product($this->g5, $this->g5Category, 'Camisa', 'Azul');
    }

    public function test_stock_is_not_editable_after_creation()
    {
        $product = $this->product($this->g5, $this->g5Category, 'Jersey', 'Rojo');
        $variant = $product->variants()->create(['size' => 'M', 'stock' => 7]);

        $this->panelAs(UserRole::Owner, $this->g5);

        $test = Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()]);
        $key = array_key_first($test->get('data.variants'));

        $test->assertSet("data.variants.$key.stock", 7)
            ->set("data.variants.$key.stock", 999)
            ->set("data.variants.$key.sku", 'JER-M')
            ->call('save')
            ->assertHasNoFormErrors();

        $variant->refresh();
        $this->assertSame(7, $variant->stock);
        $this->assertSame('JER-M', $variant->sku);
    }

    public function test_photos_are_stored_on_the_public_disk_and_keep_their_order()
    {
        Storage::fake('public');
        $this->panelAs(UserRole::Owner, $this->g5);

        Livewire::test(CreateProduct::class)
            ->fillForm($this->formData($this->g5Category, [
                'photos' => [UploadedFile::fake()->image('uno.jpg'), UploadedFile::fake()->image('dos.jpg')],
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::withoutGlobalScopes()->firstOrFail();
        $images = $product->images;

        $this->assertCount(2, $images);
        $this->assertSame([0, 1], $images->pluck('position')->all());
        foreach ($images as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_product_list_is_scoped_to_the_tenant_and_supports_trash_and_restore()
    {
        $mine = $this->product($this->g5, $this->g5Category, 'Balón', 'Blanco');
        $other = $this->product($this->coppers, $this->coppersCategory, 'Camisa', 'Azul');

        $this->panelAs(UserRole::Owner, $this->g5);

        Livewire::test(ListProducts::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other])
            ->searchTable('Balón')
            ->assertCanSeeTableRecords([$mine]);

        Livewire::test(EditProduct::class, ['record' => $mine->getRouteKey()])
            ->callAction(DeleteAction::class);

        $this->assertSoftDeleted($mine);

        Livewire::test(ListProducts::class)
            ->assertCanNotSeeTableRecords([$mine])
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$mine]);

        Livewire::test(EditProduct::class, ['record' => $mine->getRouteKey()])
            ->callAction(RestoreAction::class);

        $this->assertNotSoftDeleted($mine);
    }

    public function test_table_filters_by_category_brand_and_active()
    {
        $nike = Brand::create(['name' => 'Nike', 'slug' => 'nike']);
        $otherCategory = $this->category($this->g5, 'Jerseys');

        $a = $this->product($this->g5, $this->g5Category, 'Tenis', 'Negro', ['brand_id' => $nike->id]);
        $b = $this->product($this->g5, $otherCategory, 'Jersey', 'Rojo', ['is_active' => false]);
        $a->variants()->createMany([['size' => '26', 'stock' => 4], ['size' => '27', 'stock' => 3]]);

        $this->panelAs(UserRole::Owner, $this->g5);

        Livewire::test(ListProducts::class)
            ->assertTableColumnStateSet('variants_sum_stock', 7, $a)
            ->filterTable('category', $otherCategory->id)
            ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a])
            ->resetTableFilters()
            ->filterTable('brand', $nike->id)
            ->assertCanSeeTableRecords([$a])->assertCanNotSeeTableRecords([$b])
            ->resetTableFilters()
            ->filterTable('is_active', false)
            ->assertCanSeeTableRecords([$b])->assertCanNotSeeTableRecords([$a]);
    }

    public function test_category_slug_is_generated_and_brands_are_global()
    {
        $this->panelAs(UserRole::Staff, $this->g5);

        Livewire::test(CreateCategory::class)
            ->fillForm(['name' => 'Tenis de Fútbol', 'size_type' => SizeType::Footwear])
            ->call('create')
            ->assertHasNoFormErrors();

        $category = Category::withoutGlobalScopes()->where('slug', 'tenis-de-futbol')->firstOrFail();
        $this->assertSame($this->g5->id, $category->store_id);

        Brand::create(['name' => 'Nike', 'slug' => 'nike']);

        $this->get('/admin/g5/brands')->assertOk()->assertSee('Nike');
        $this->get('/admin/g5/products')->assertOk();
        $this->get('/admin/g5/categories')->assertOk()->assertSee('Tenis de Fútbol')->assertDontSee('Camisas');
    }

    public function test_hidden_cost_still_loads_in_the_owner_edit_form_and_survives_saving_other_fields()
    {
        $product = $this->product($this->g5, $this->g5Category, 'Jersey', 'Rojo', ['cost' => 50]);
        $product->variants()->create(['size' => 'M', 'stock' => 1]);

        $this->panelAs(UserRole::Owner, $this->g5);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertFormSet(['cost' => '50.00'])
            ->fillForm(['price' => 120])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $product->fresh();
        $this->assertSame('50.00', $fresh->cost);
        $this->assertSame('120.00', $fresh->price);
        $this->assertArrayNotHasKey('cost', $fresh->toArray());
    }

    public function test_a_category_of_another_store_is_rejected_even_if_the_id_is_forged()
    {
        $this->panelAs(UserRole::Staff, $this->coppers);

        Livewire::test(CreateProduct::class)
            ->fillForm($this->formData($this->g5Category))
            ->call('create')
            ->assertHasFormErrors(['category_id']);

        $this->assertDatabaseCount('products', 0);

        // Y al editar un producto propio, cambiarle la categoría a una de la otra tienda tampoco pasa.
        $product = $this->product($this->coppers, $this->coppersCategory, 'Camisa', 'Azul');
        $product->variants()->create(['size' => 'M', 'stock' => 1]);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->set('data.category_id', $this->g5Category->id)
            ->call('save')
            ->assertHasFormErrors(['category_id']);

        $this->assertSame($this->coppersCategory->id, $product->fresh()->category_id);
    }

    public function test_a_category_of_the_same_store_still_saves()
    {
        $this->panelAs(UserRole::Staff, $this->coppers);

        Livewire::test(CreateProduct::class)
            ->fillForm($this->formData($this->coppersCategory))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('products', 1);
    }
}
