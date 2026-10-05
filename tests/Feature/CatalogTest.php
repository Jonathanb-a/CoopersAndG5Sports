<?php

namespace Tests\Feature;

use App\Enums\SizeType;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CatalogTest extends TestCase
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

    private function category(Store $store, string $name): Category
    {
        return Category::firstOrCreate(
            ['store_id' => $store->id, 'slug' => str($name)->slug()],
            ['name' => $name, 'size_type' => SizeType::OneSize],
        );
    }

    /** @param array<string, int> $sizes talla => stock */
    private function product(Store $store, string $name, array $sizes = ['M' => 5], array $extra = [], string $category = 'General', ?string $brand = null): Product
    {
        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $this->category($store, $category)->id,
            'brand_id' => $brand ? Brand::firstOrCreate(['slug' => str($brand)->slug()], ['name' => $brand])->id : null,
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

    /** Nombres de los productos que devuelve el catálogo, en orden. */
    private function names(string $url, string $host = 'coppers.localhost'): array
    {
        $names = [];

        $this->get("http://{$host}{$url}")
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$names) {
                $page->component('catalog');
                $names = collect($page->toArray()['props']['products'])->pluck('name')->all();
            });

        return $names;
    }

    public function test_the_size_filter_returns_only_products_with_that_size()
    {
        $this->product($this->g5, 'Tenis 26', ['26' => 3, '27' => 2]);
        $this->product($this->g5, 'Tenis 27', ['27' => 1]);
        $this->product($this->g5, 'Tenis 28', ['28' => 4]);
        $this->product($this->g5, 'Sin stock en 28', ['28' => 0, '27' => 2]);

        $this->assertEqualsCanonicalizing(['Tenis 26', 'Tenis 27', 'Sin stock en 28'], $this->names('/catalogo?talla[]=27', 'g5.localhost'));
        $this->assertEqualsCanonicalizing(['Tenis 28', 'Sin stock en 28'], $this->names('/catalogo?talla[]=28', 'g5.localhost'));
        $this->assertEqualsCanonicalizing(['Tenis 26', 'Tenis 28', 'Sin stock en 28', 'Tenis 27'], $this->names('/catalogo?talla[]=26&talla[]=28&talla[]=27', 'g5.localhost'));
        $this->assertSame([], $this->names('/catalogo?talla[]=30', 'g5.localhost'));
    }

    public function test_only_available_hides_sold_out_products_without_backorder()
    {
        $this->product($this->coppers, 'Con piezas', ['M' => 3]);
        $this->product($this->coppers, 'Última pieza', ['M' => 1]);
        $this->product($this->coppers, 'Sobre pedido', ['M' => 0], ['allow_backorder' => true]);
        $this->product($this->coppers, 'Agotado', ['M' => 0, 'L' => 0]);

        $this->assertCount(4, $this->names('/catalogo'));

        $this->assertEqualsCanonicalizing(
            ['Con piezas', 'Última pieza', 'Sobre pedido'],
            $this->names('/catalogo?disponibles=1'),
        );
    }

    public function test_only_available_with_a_size_needs_pieces_in_that_size()
    {
        $this->product($this->coppers, 'Hay en M', ['M' => 2, 'L' => 0]);
        $this->product($this->coppers, 'Solo hay en L', ['M' => 0, 'L' => 2]);

        $this->assertSame(['Hay en M'], $this->names('/catalogo?talla[]=M&disponibles=1'));
        $this->assertEqualsCanonicalizing(['Hay en M', 'Solo hay en L'], $this->names('/catalogo?talla[]=M'));
    }

    public function test_inactive_and_deleted_products_do_not_appear()
    {
        $this->product($this->coppers, 'Visible');
        $this->product($this->coppers, 'Apagado', extra: ['is_active' => false]);
        $this->product($this->coppers, 'Borrado')->delete();

        $this->assertSame(['Visible'], $this->names('/catalogo'));
        $this->assertSame(['Visible'], $this->names('/catalogo?q=Vis'));
        $this->assertSame([], $this->names('/catalogo?q=Apagado'));
        $this->assertSame([], $this->names('/catalogo?q=Borrado'));
    }

    public function test_a_category_from_the_other_store_responds_404()
    {
        $this->product($this->g5, 'Tenis', category: 'Calzado');
        $this->product($this->coppers, 'Camisa', category: 'Camisas');

        $this->get('http://coppers.localhost/categoria/calzado')->assertNotFound();
        $this->get('http://g5.localhost/categoria/camisas')->assertNotFound();
        $this->get('http://coppers.localhost/categoria/no-existe')->assertNotFound();

        $this->assertSame(['Camisa'], $this->names('/categoria/camisas'));
        $this->assertSame(['Tenis'], $this->names('/categoria/calzado', 'g5.localhost'));
    }

    public function test_the_catalog_only_shows_the_current_stores_products()
    {
        $this->product($this->coppers, 'Camisa');
        $this->product($this->g5, 'Tenis');

        $this->assertSame(['Camisa'], $this->names('/catalogo'));
        $this->assertSame(['Tenis'], $this->names('/catalogo', 'g5.localhost'));
    }

    public function test_a_category_page_only_shows_its_products_and_exposes_the_category()
    {
        $this->product($this->coppers, 'Camisa', category: 'Camisas');
        $this->product($this->coppers, 'Pantalón', category: 'Pantalones');

        $this->get('http://coppers.localhost/categoria/camisas')
            ->assertInertia(fn (Assert $page) => $page
                ->where('category.slug', 'camisas')
                ->where('category.name', 'Camisas')
                ->has('products', 1)
                ->where('products.0.name', 'Camisa'));

        $this->get('http://coppers.localhost/catalogo')
            ->assertInertia(fn (Assert $page) => $page->where('category', null)->has('products', 2));
    }

    public function test_the_brand_filter_and_the_filter_options()
    {
        $this->product($this->g5, 'Mercurial', ['27' => 1, 'M' => 1], brand: 'Nike');
        $this->product($this->g5, 'Predator', ['26.5' => 1, '9' => 1], brand: 'Adidas');
        $this->product($this->g5, 'Sin marca', ['XL' => 1, 'S' => 1]);
        $this->product($this->coppers, 'De Copper\'s', ['XXL' => 1], brand: 'Zara');

        $this->assertSame(['Mercurial'], $this->names('/catalogo?marca[]=nike', 'g5.localhost'));
        $this->assertEqualsCanonicalizing(['Mercurial', 'Predator'], $this->names('/catalogo?marca[]=nike&marca[]=adidas', 'g5.localhost'));

        $this->get('http://g5.localhost/catalogo')
            ->assertInertia(fn (Assert $page) => $page
                ->where('options.brands', fn ($brands) => collect($brands)->pluck('name')->all() === ['Adidas', 'Nike'])
                // Primero las numéricas de menor a mayor, luego las de letras en su orden; nada de la otra tienda.
                ->where('options.sizes', ['9', '26.5', '27', 'S', 'M', 'XL']));
    }

    public function test_search_finds_products_by_name_and_treats_wildcards_as_text()
    {
        $this->product($this->coppers, 'Camisa Oxford');
        $this->product($this->coppers, 'Camisa de lino');
        $this->product($this->coppers, 'Pantalón 100% algodón');
        $this->product($this->g5, 'Camisa de G5');

        $this->assertEqualsCanonicalizing(['Camisa Oxford', 'Camisa de lino'], $this->names('/catalogo?q=camisa'));
        $this->assertSame(['Camisa Oxford'], $this->names('/catalogo?q=oxf'));
        $this->assertSame(['Pantalón 100% algodón'], $this->names('/catalogo?q=100%25'));
        $this->assertSame([], $this->names('/catalogo?q=%25'.'xyz'));
        $this->assertCount(3, $this->names('/catalogo?q='));
    }

    public function test_sorting_by_newest_and_by_price()
    {
        foreach ([['Barato', 50, 3], ['Medio', 100, 1], ['Caro', 300, 2]] as [$name, $price, $daysAgo]) {
            $this->product($this->coppers, $name, extra: ['price' => $price])
                ->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }

        $this->assertSame(['Medio', 'Caro', 'Barato'], $this->names('/catalogo'));
        $this->assertSame(['Medio', 'Caro', 'Barato'], $this->names('/catalogo?orden=nuevos'));
        $this->assertSame(['Barato', 'Medio', 'Caro'], $this->names('/catalogo?orden=precio-menor'));
        $this->assertSame(['Caro', 'Medio', 'Barato'], $this->names('/catalogo?orden=precio-mayor'));
        // Un orden desconocido no rompe la página: usa el de siempre.
        $this->assertSame(['Medio', 'Caro', 'Barato'], $this->names('/catalogo?orden=lo-que-sea'));
    }

    public function test_it_paginates_twelve_products_and_keeps_the_filters_in_the_links()
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->product($this->coppers, sprintf('Producto %02d', $i), extra: ['price' => $i])
                ->forceFill(['created_at' => now()->subMinutes(31 - $i)])->save();
        }

        $this->get('http://coppers.localhost/catalogo?orden=precio-menor')
            ->assertInertia(fn (Assert $page) => $page
                ->has('products', 12)
                ->where('products.0.name', 'Producto 01')
                ->where('pagination.total', 30)
                ->where('pagination.last', 3)
                ->where('pagination.current', 1)
                ->where('pagination.prev_url', null)
                ->where('pagination.next_url', 'http://coppers.localhost/catalogo?orden=precio-menor&pagina=2'));

        $this->get('http://coppers.localhost/catalogo?orden=precio-menor&pagina=3')
            ->assertInertia(fn (Assert $page) => $page
                ->has('products', 6)
                ->where('products.0.name', 'Producto 25')
                ->where('pagination.from', 25)
                ->where('pagination.to', 30)
                ->where('pagination.next_url', null));
    }

    public function test_a_page_past_the_end_redirects_to_the_last_page()
    {
        for ($i = 1; $i <= 13; $i++) {
            $this->product($this->coppers, "Producto {$i}");
        }

        $this->get('http://coppers.localhost/catalogo?pagina=9&orden=precio-menor')
            ->assertRedirect('http://coppers.localhost/catalogo?orden=precio-menor&pagina=2');
    }

    public function test_an_empty_result_returns_no_products_and_one_empty_page()
    {
        $this->product($this->coppers, 'Camisa');

        $this->get('http://coppers.localhost/catalogo?q=zapato')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products', [])
                ->where('pagination.total', 0)
                ->where('filters.q', 'zapato'));
    }

    public function test_product_cards_carry_brand_photo_price_and_tag()
    {
        $product = $this->product($this->coppers, 'Camisa', ['M' => 1], brand: 'Zara');
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/camisa.jpg', 'position' => 0]);

        $this->get('http://coppers.localhost/catalogo')
            ->assertInertia(fn (Assert $page) => $page
                ->where('products.0.brand', 'Zara')
                ->where('products.0.name', 'Camisa')
                ->where('products.0.price', '100.00')
                ->where('products.0.image_url', '/storage/products/camisa.jpg')
                ->where('products.0.tag', 'last-piece'));
    }

    public function test_the_filters_are_echoed_so_the_url_can_be_shared()
    {
        $this->get('http://coppers.localhost/catalogo?q=%20lino%20&marca[]=zara&talla=M&disponibles=1&orden=precio-mayor')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', [
                    'q' => 'lino',
                    'marca' => ['zara'],
                    'talla' => ['M'],
                    'disponibles' => true,
                    'orden' => 'precio-mayor',
                ]));

        $this->get('http://coppers.localhost/catalogo')
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters', ['q' => '', 'marca' => [], 'talla' => [], 'disponibles' => false, 'orden' => 'nuevos']));
    }
}
