<?php

namespace Tests\Feature;

use App\Enums\SizeType;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreHomeTest extends TestCase
{
    use RefreshDatabase;

    private Store $coppers;

    private Store $g5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5']);
    }

    private function banner(Store $store, string $title, int $position, bool $active = true): Banner
    {
        return Banner::create([
            'store_id' => $store->id,
            'image_path' => 'banners/'.str($title)->slug().'.jpg',
            'title' => $title,
            'link_url' => '/promo',
            'position' => $position,
            'is_active' => $active,
        ]);
    }

    private function category(Store $store, string $name): Category
    {
        return Category::firstOrCreate(
            ['store_id' => $store->id, 'slug' => str($name)->slug()],
            ['name' => $name, 'size_type' => SizeType::OneSize],
        );
    }

    /** @param array<string, int> $sizes talla => stock */
    private function product(Store $store, string $name, array $sizes = ['M' => 5], array $extra = [], ?string $category = null): Product
    {
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $this->category($store, $category ?? 'General')->id,
            'name' => $name,
            'slug' => str($name)->slug(),
            'price' => 100,
            ...$extra,
        ]);

        foreach ($sizes as $size => $stock) {
            $product->variants()->create(['size' => (string) $size, 'stock' => $stock]);
        }

        return $product;
    }

    public function test_coppers_home_does_not_show_g5_banners_or_products()
    {
        $this->banner($this->coppers, 'Colección otoño', 1);
        $this->banner($this->g5, 'Mundial G5', 1);
        $this->product($this->coppers, 'Camisa Oxford', category: 'Camisas');
        $this->product($this->g5, 'Nike Mercurial', category: 'Calzado');
        $this->coppers->update(['about_text' => 'Somos Copper\'s.']);
        $this->g5->update(['about_text' => 'Somos G5.']);

        $this->get('http://coppers.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->has('banners', 1)
                ->where('banners.0.title', 'Colección otoño')
                ->has('newest', 1)
                ->where('newest.0.name', 'Camisa Oxford')
                ->has('categoryCards', 1)
                ->where('categoryCards.0.name', 'Camisas')
                ->where('about', "Somos Copper's."));

        $this->get('http://g5.localhost/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('banners', 1)
                ->where('banners.0.title', 'Mundial G5')
                ->has('newest', 1)
                ->where('newest.0.name', 'Nike Mercurial')
                ->where('about', 'Somos G5.'));
    }

    public function test_inactive_banners_do_not_appear_and_the_rest_follow_the_panel_order()
    {
        $this->banner($this->coppers, 'Tercero', 3);
        $this->banner($this->coppers, 'Primero', 1);
        $this->banner($this->coppers, 'Apagado', 2, active: false);
        $this->banner($this->coppers, 'Segundo', 2);

        $this->get('http://coppers.localhost/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('banners', 3)
                ->where('banners.0.title', 'Primero')
                ->where('banners.1.title', 'Segundo')
                ->where('banners.2.title', 'Tercero')
                ->where('banners.0.image_url', '/storage/banners/primero.jpg')
                ->where('banners.0.link_url', '/promo'));
    }

    public function test_without_active_banners_the_list_is_empty()
    {
        $this->banner($this->coppers, 'Apagado', 1, active: false);
        $this->banner($this->g5, 'De otra tienda', 1);

        $this->get('http://coppers.localhost/')
            ->assertInertia(fn (Assert $page) => $page->where('banners', []));
    }

    public function test_newest_shows_the_eight_most_recent_active_products()
    {
        for ($i = 1; $i <= 10; $i++) {
            $product = $this->product($this->coppers, "Producto {$i}");
            $product->forceFill(['created_at' => now()->subDays(20 - $i)])->save();
        }
        $hidden = $this->product($this->coppers, 'Oculto', extra: ['is_active' => false]);
        $hidden->forceFill(['created_at' => now()->addDay()])->save();
        $trashed = $this->product($this->coppers, 'Eliminado');
        $trashed->forceFill(['created_at' => now()->addDay()])->save();
        $trashed->delete();
        $this->product($this->g5, 'De G5')->forceFill(['created_at' => now()->addDay()])->save();

        $this->get('http://coppers.localhost/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('newest', 8)
                ->where('newest.0.name', 'Producto 10')
                ->where('newest.7.name', 'Producto 3')
                ->where('newest', fn ($newest) => collect($newest)->pluck('name')->doesntContain(['Oculto', 'Eliminado', 'De G5', 'Producto 1', 'Producto 2'])));
    }

    public function test_newest_products_carry_their_photo_and_stock_tag()
    {
        $withPhoto = $this->product($this->coppers, 'Con foto', ['M' => 5]);
        ProductImage::create(['product_id' => $withPhoto->id, 'path' => 'products/segunda.jpg', 'position' => 1]);
        ProductImage::create(['product_id' => $withPhoto->id, 'path' => 'products/primera.jpg', 'position' => 0]);
        $this->product($this->coppers, 'Agotado', ['M' => 0, 'L' => 0]);
        $this->product($this->coppers, 'Sobre pedido', ['M' => 0], ['allow_backorder' => true]);
        $this->product($this->coppers, 'Última pieza', ['M' => 1, 'L' => 0]);
        $this->product($this->coppers, 'Normal', ['M' => 2]);

        $this->get('http://coppers.localhost/')
            ->assertInertia(fn (Assert $page) => $page
                ->where('newest', function ($newest) {
                    $byName = collect($newest)->keyBy('name');

                    return $byName['Con foto']['image_url'] === '/storage/products/primera.jpg'
                        && $byName['Con foto']['tag'] === null
                        && $byName['Agotado']['tag'] === 'sold-out'
                        && $byName['Agotado']['image_url'] === null
                        && $byName['Sobre pedido']['tag'] === 'backorder'
                        && $byName['Última pieza']['tag'] === 'last-piece'
                        && $byName['Normal']['tag'] === null
                        && $byName['Normal']['price'] === '100.00';
                }));
    }

    public function test_category_cards_count_active_products_and_use_a_product_photo()
    {
        $shirt = $this->product($this->coppers, 'Camisa vieja', category: 'Camisas');
        $newShirt = $this->product($this->coppers, 'Camisa nueva', category: 'Camisas');
        ProductImage::create(['product_id' => $shirt->id, 'path' => 'products/vieja.jpg', 'position' => 0]);
        ProductImage::create(['product_id' => $newShirt->id, 'path' => 'products/nueva.jpg', 'position' => 0]);
        $this->product($this->coppers, 'Camisa oculta', category: 'Camisas', extra: ['is_active' => false]);
        $this->product($this->coppers, 'Pantalón', category: 'Pantalones');
        $this->category($this->coppers, 'Vacía');
        $this->product($this->g5, 'Tenis', category: 'Calzado');

        $this->get('http://coppers.localhost/')
            ->assertInertia(fn (Assert $page) => $page
                ->has('categoryCards', 2)
                ->where('categoryCards.0.name', 'Camisas')
                ->where('categoryCards.0.products_count', 2)
                ->where('categoryCards.0.image_url', '/storage/products/nueva.jpg')
                ->where('categoryCards.1.name', 'Pantalones')
                ->where('categoryCards.1.products_count', 1)
                ->where('categoryCards.1.image_url', null));
    }

    public function test_about_is_hidden_when_empty()
    {
        $this->get('http://coppers.localhost/')->assertInertia(fn (Assert $page) => $page->where('about', null));

        $this->coppers->update(['about_text' => "  \n  "]);
        $this->get('http://coppers.localhost/')->assertInertia(fn (Assert $page) => $page->where('about', null));

        $this->coppers->update(['about_text' => "Primer párrafo.\n\nSegundo párrafo."]);
        $this->get('http://coppers.localhost/')->assertInertia(fn (Assert $page) => $page->where('about', "Primer párrafo.\n\nSegundo párrafo."));
    }
}
