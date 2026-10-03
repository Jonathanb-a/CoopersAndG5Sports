<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $stores = [
            ['slug' => 'coppers', 'name' => "Copper's", 'domain' => 'coppers.localhost', 'products' => ['Camisa Oxford', 'Pantalón Chino']],
            ['slug' => 'g5', 'name' => 'Sports G5', 'domain' => 'g5.localhost', 'products' => ['Nike Mercurial Vapor']],
        ];

        foreach ($stores as $data) {
            $store = Store::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'domain' => $data['domain'],
                'theme' => $data['slug'],
            ]);

            $category = Category::create([
                'store_id' => $store->id,
                'name' => 'General',
                'slug' => 'general',
                'size_type' => 'unica',
            ]);

            foreach ($data['products'] as $name) {
                Product::create([
                    'store_id' => $store->id,
                    'category_id' => $category->id,
                    'name' => $name,
                    'slug' => str($name)->slug(),
                    'price' => 100,
                ]);
            }
        }
    }

    public function test_coppers_domain_shows_only_coppers_products()
    {
        $this->get('http://coppers.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->where('store.slug', 'coppers')
                ->where('store.name', "Copper's")
                ->has('products', 2)
                ->where('products.0.name', 'Camisa Oxford')
                ->where('products.1.name', 'Pantalón Chino'));
    }

    public function test_g5_domain_shows_only_g5_products()
    {
        $this->get('http://g5.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->where('store.slug', 'g5')
                ->where('store.theme', 'g5')
                ->has('products', 1)
                ->where('products.0.name', 'Nike Mercurial Vapor'));
    }

    public function test_the_port_is_ignored_when_resolving_the_store()
    {
        $this->get('http://coppers.localhost:8000/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('store.slug', 'coppers'));
    }

    public function test_unknown_domain_responds_404()
    {
        $this->get('http://desconocida.localhost/')->assertNotFound();
    }

    public function test_inactive_store_responds_404()
    {
        Store::where('slug', 'g5')->update(['is_active' => false]);

        $this->get('http://g5.localhost/')->assertNotFound();
    }

    public function test_admin_loads_without_a_resolved_store()
    {
        $this->get('http://localhost/admin/login')->assertOk();
        $this->get('http://localhost/admin')->assertRedirect('/admin/login');

        $this->assertFalse(app()->bound('currentStore'));
    }

    public function test_scope_is_skipped_without_a_store_and_store_id_is_assigned_with_one()
    {
        $this->assertSame(3, Product::count());

        $g5 = Store::where('slug', 'g5')->first();
        app()->instance('currentStore', $g5);

        $this->assertSame(1, Product::count());

        $product = Product::create([
            'category_id' => Category::withoutGlobalScopes()->where('store_id', $g5->id)->value('id'),
            'name' => 'Jersey',
            'slug' => 'jersey',
            'price' => 50,
        ]);

        $this->assertSame($g5->id, $product->store_id);
    }
}
