<?php

namespace Tests\Feature\Admin;

use App\Enums\SizeType;
use App\Enums\UserRole;
use App\Filament\Resources\Brands\Pages\CreateBrand;
use App\Filament\Resources\Brands\Pages\EditBrand;
use App\Filament\Resources\Brands\Pages\ListBrands;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Las marcas se comparten entre tiendas: cualquiera crea, solo el owner edita o borra, y en uso no se borran. */
class BrandResourceTest extends TestCase
{
    use RefreshDatabase;

    private Store $coppers;

    private Store $g5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost']);
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

    private function brand(string $name): Brand
    {
        return Brand::create(['name' => $name, 'slug' => str($name)->slug()]);
    }

    /** Una marca usada por un producto de la tienda indicada. */
    private function usedBrand(Store $store, string $name = 'Nike'): Brand
    {
        $brand = $this->brand($name);
        $category = Category::create(['store_id' => $store->id, 'name' => 'General', 'slug' => 'general', 'size_type' => SizeType::OneSize]);
        Product::create(['store_id' => $store->id, 'category_id' => $category->id, 'brand_id' => $brand->id, 'name' => 'Tenis', 'slug' => 'tenis', 'price' => 100]);

        return $brand;
    }

    public function test_any_staff_can_create_a_brand()
    {
        $this->panelAs(UserRole::Staff, $this->coppers);

        Livewire::test(CreateBrand::class)
            ->fillForm(['name' => 'Adidas', 'slug' => 'adidas'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('brands', ['slug' => 'adidas']);
    }

    public function test_staff_can_not_edit_or_delete_a_brand()
    {
        $brand = $this->brand('Puma');
        $this->panelAs(UserRole::Staff, $this->coppers);

        Livewire::test(ListBrands::class)
            ->assertTableActionHidden('edit', record: $brand)
            ->assertTableActionHidden('delete', record: $brand);

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])->assertForbidden();

        $this->assertSame('Puma', $brand->fresh()->name);
    }

    public function test_staff_of_one_store_can_not_rename_a_brand_used_by_the_other_store()
    {
        $brand = $this->usedBrand($this->g5, 'Nike');
        $this->panelAs(UserRole::Staff, $this->coppers);

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])->assertForbidden();
        Livewire::test(ListBrands::class)->assertTableActionHidden('delete', record: $brand);

        $this->assertSame('Nike', $brand->fresh()->name);
    }

    public function test_the_owner_can_edit_a_brand()
    {
        $brand = $this->usedBrand($this->g5, 'Nike');
        $this->panelAs(UserRole::Owner, $this->coppers);

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->fillForm(['name' => 'Nike Sportswear'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nike Sportswear', $brand->fresh()->name);
    }

    public function test_a_brand_in_use_can_not_be_deleted_even_by_the_owner()
    {
        // Usada solo por un producto de G5: el owner la ve desde Copper's y aun así no se borra.
        $brand = $this->usedBrand($this->g5);
        $this->panelAs(UserRole::Owner, $this->coppers);

        Livewire::test(ListBrands::class)
            ->callTableAction('delete', $brand)
            ->assertNotified('No se puede borrar la marca');

        Livewire::test(EditBrand::class, ['record' => $brand->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('No se puede borrar la marca');

        $this->assertNotNull(Brand::find($brand->id));
    }

    public function test_a_brand_whose_only_product_is_in_the_trash_still_counts_as_in_use()
    {
        $brand = $this->usedBrand($this->g5);
        Product::withoutGlobalScopes()->where('brand_id', $brand->id)->first()->delete();
        $this->panelAs(UserRole::Owner, $this->g5);

        Livewire::test(ListBrands::class)->callTableAction('delete', $brand);

        $this->assertNotNull(Brand::find($brand->id));
    }

    public function test_the_owner_can_delete_an_unused_brand()
    {
        $brand = $this->brand('Sin uso');
        $this->panelAs(UserRole::Owner, $this->coppers);

        Livewire::test(ListBrands::class)->callTableAction('delete', $brand);

        $this->assertNull(Brand::find($brand->id));
    }
}
