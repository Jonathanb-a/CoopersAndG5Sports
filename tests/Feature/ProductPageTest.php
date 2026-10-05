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

class ProductPageTest extends TestCase
{
    use RefreshDatabase;

    private Store $coppers;

    private Store $g5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->coppers = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost', 'theme' => 'coppers', 'whatsapp_phone' => '5511111111']);
        $this->g5 = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost', 'theme' => 'g5', 'whatsapp_phone' => '+52 33 2222 2222']);
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

    public function test_a_product_from_the_other_store_responds_404()
    {
        $this->product($this->g5, 'Tenis Mercurial');
        $this->product($this->coppers, 'Camisa Oxford');

        $this->get('http://coppers.localhost/producto/tenis-mercurial')->assertNotFound();
        $this->get('http://g5.localhost/producto/camisa-oxford')->assertNotFound();
        $this->get('http://coppers.localhost/producto/no-existe')->assertNotFound();

        $this->get('http://coppers.localhost/producto/camisa-oxford')->assertOk();
        $this->get('http://g5.localhost/producto/tenis-mercurial')->assertOk();
    }

    public function test_inactive_and_deleted_products_respond_404()
    {
        $this->product($this->coppers, 'Apagado', extra: ['is_active' => false]);
        $this->product($this->coppers, 'Borrado')->delete();

        $this->get('http://coppers.localhost/producto/apagado')->assertNotFound();
        $this->get('http://coppers.localhost/producto/borrado')->assertNotFound();
    }

    public function test_the_same_slug_in_both_stores_shows_each_stores_own_product()
    {
        $this->product($this->coppers, 'Gorra', extra: ['price' => 250]);
        $this->product($this->g5, 'Gorra', extra: ['price' => 399]);

        $this->get('http://coppers.localhost/producto/gorra')->assertInertia(fn (Assert $page) => $page->where('product.price', '250.00'));
        $this->get('http://g5.localhost/producto/gorra')->assertInertia(fn (Assert $page) => $page->where('product.price', '399.00'));
    }

    public function test_the_whatsapp_link_carries_the_current_stores_number()
    {
        $this->product($this->coppers, 'Camisa Oxford');
        $this->product($this->g5, 'Tenis Mercurial');

        // Un número de 10 dígitos se toma como mexicano (+52); uno con formato se limpia.
        $this->get('http://coppers.localhost/producto/camisa-oxford')
            ->assertInertia(fn (Assert $page) => $page
                ->where('whatsapp.url', 'https://wa.me/525511111111')
                ->where('whatsapp.product_url', 'http://coppers.localhost/producto/camisa-oxford'));

        $this->get('http://g5.localhost/producto/tenis-mercurial')
            ->assertInertia(fn (Assert $page) => $page
                ->where('whatsapp.url', 'https://wa.me/523322222222')
                ->where('whatsapp.product_url', 'http://g5.localhost/producto/tenis-mercurial'));
    }

    public function test_without_a_whatsapp_number_there_is_no_link()
    {
        $this->coppers->update(['whatsapp_phone' => null]);
        $this->product($this->coppers, 'Camisa Oxford');

        $this->get('http://coppers.localhost/producto/camisa-oxford')
            ->assertInertia(fn (Assert $page) => $page->where('whatsapp.url', null));
    }

    public function test_a_sold_out_size_without_backorder_is_not_selectable()
    {
        $product = $this->product($this->g5, 'Tenis', ['26' => 3, '27' => 1, '28' => 0]);
        $ids = $product->variants()->pluck('id', 'size');

        $this->get('http://g5.localhost/producto/tenis')
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.sizes', [
                    ['variant_id' => $ids['26'], 'size' => '26', 'status' => 'available', 'selectable' => true],
                    ['variant_id' => $ids['27'], 'size' => '27', 'status' => 'last-piece', 'selectable' => true],
                    ['variant_id' => $ids['28'], 'size' => '28', 'status' => 'sold-out', 'selectable' => false],
                ]));
    }

    public function test_a_sold_out_size_with_backorder_is_selectable_and_carries_the_days()
    {
        $this->product($this->coppers, 'Camisa', ['S' => 0, 'M' => 4], ['allow_backorder' => true, 'backorder_days' => 7]);

        $this->get('http://coppers.localhost/producto/camisa')
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.allow_backorder', true)
                ->where('product.backorder_days', 7)
                ->where('product.sizes.0', fn ($size) => collect($size)->except('variant_id')->all() === ['size' => 'S', 'status' => 'backorder', 'selectable' => true])
                ->where('product.sizes.1', fn ($size) => collect($size)->except('variant_id')->all() === ['size' => 'M', 'status' => 'available', 'selectable' => true]));
    }

    public function test_backorder_days_are_ignored_when_the_product_does_not_allow_backorder()
    {
        $this->product($this->coppers, 'Camisa', ['S' => 0], ['allow_backorder' => false, 'backorder_days' => 7]);

        $this->get('http://coppers.localhost/producto/camisa')
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.backorder_days', null)
                ->where('product.sizes.0.selectable', false));
    }

    public function test_sizes_are_sorted_and_deleted_ones_are_not_listed_and_stock_is_not_exposed()
    {
        $product = $this->product($this->coppers, 'Camisa', ['XL' => 1, 'S' => 2, 'M' => 3, '30' => 1, 'Vieja' => 9]);
        $product->variants()->where('size', 'Vieja')->first()->delete();

        $this->get('http://coppers.localhost/producto/camisa')
            ->assertInertia(fn (Assert $page) => $page
                ->where('product.sizes', fn ($sizes) => collect($sizes)->pluck('size')->all() === ['30', 'S', 'M', 'XL']
                    && collect($sizes)->every(fn ($size) => array_keys($size) === ['variant_id', 'size', 'status', 'selectable'])));
    }

    public function test_the_page_carries_brand_name_color_price_description_and_photos_in_order()
    {
        $product = $this->product($this->coppers, 'Camisa Oxford', brand: 'Zara', extra: [
            'color' => 'Azul',
            'price' => 1700,
            'description' => "Algodón.\n\nLavar a mano.",
        ]);
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/segunda.jpg', 'position' => 1]);
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/primera.jpg', 'position' => 0]);

        $this->get('http://coppers.localhost/producto/camisa-oxford')
            ->assertInertia(fn (Assert $page) => $page
                ->component('product')
                ->where('product.brand', 'Zara')
                ->where('product.name', 'Camisa Oxford')
                ->where('product.color', 'Azul')
                ->where('product.price', '1700.00')
                ->where('product.description', "Algodón.\n\nLavar a mano.")
                ->where('product.images', ['/storage/products/primera.jpg', '/storage/products/segunda.jpg'])
                ->where('product.category.slug', 'general')
                ->missing('product.cost'));
    }

    public function test_related_shows_four_products_from_the_same_category_of_this_store()
    {
        $main = $this->product($this->coppers, 'Principal', category: 'Camisas');
        foreach (range(1, 6) as $i) {
            $this->product($this->coppers, "Camisa {$i}", category: 'Camisas')
                ->forceFill(['created_at' => now()->subDays(10 - $i)])->save();
        }
        $this->product($this->coppers, 'Pantalón', category: 'Pantalones');
        $this->product($this->coppers, 'Camisa apagada', category: 'Camisas', extra: ['is_active' => false]);
        $this->product($this->coppers, 'Camisa borrada', category: 'Camisas')->delete();
        $this->product($this->g5, 'Camisa de G5', category: 'Camisas');

        $this->get('http://coppers.localhost/producto/'.$main->slug)
            ->assertInertia(fn (Assert $page) => $page
                ->has('related', 4)
                ->where('related', fn ($related) => collect($related)->pluck('name')->all() === ['Camisa 6', 'Camisa 5', 'Camisa 4', 'Camisa 3'])
                ->where('related.0.slug', 'camisa-6'));
    }

    public function test_related_is_empty_when_the_product_is_alone_in_its_category()
    {
        $this->product($this->coppers, 'Único', category: 'Camisas');
        $this->product($this->coppers, 'Pantalón', category: 'Pantalones');

        $this->get('http://coppers.localhost/producto/unico')
            ->assertInertia(fn (Assert $page) => $page->where('related', []));
    }

    public function test_open_graph_tags_are_in_the_html_with_the_product_photo()
    {
        $product = $this->product($this->coppers, 'Camisa Oxford', brand: 'Zara', extra: [
            'color' => 'Azul',
            'description' => "Camisa de algodón\ncon <b>cuello</b> \"italiano\".",
        ]);
        ProductImage::create(['product_id' => $product->id, 'path' => 'products/camisa.jpg', 'position' => 0]);

        $html = $this->get('http://coppers.localhost/producto/camisa-oxford')->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:title" content="Zara Camisa Oxford Azul · Copper&#039;s">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="http://coppers.localhost/storage/products/camisa.jpg">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="http://coppers.localhost/producto/camisa-oxford">', $html);
        $this->assertStringContainsString('<meta property="og:site_name" content="Copper&#039;s">', $html);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
        // La descripción va en una sola línea y con el HTML escapado.
        $this->assertStringContainsString('content="Camisa de algodón con &lt;b&gt;cuello&lt;/b&gt; &quot;italiano&quot;."', $html);
        $this->assertStringContainsString('<title inertia>Zara Camisa Oxford Azul · Copper&#039;s</title>', $html);
    }

    public function test_open_graph_falls_back_without_photo_or_description()
    {
        $this->product($this->g5, 'Balón');

        $html = $this->get('http://g5.localhost/producto/balon')->assertOk()->getContent();

        $this->assertStringContainsString('<meta property="og:description" content="Balón en Sports G5.">', $html);
        $this->assertStringNotContainsString('og:image', $html);
    }

    public function test_other_pages_do_not_get_product_meta_tags()
    {
        $html = $this->get('http://coppers.localhost/')->assertOk()->getContent();

        $this->assertStringNotContainsString('og:title', $html);
    }

    public function test_catalog_cards_link_to_the_product_page()
    {
        $this->product($this->coppers, 'Camisa Oxford');

        $this->get('http://coppers.localhost/catalogo')->assertInertia(fn (Assert $page) => $page->where('products.0.slug', 'camisa-oxford'));
    }

    public function test_home_cards_link_to_the_product_page()
    {
        $this->product($this->coppers, 'Camisa Oxford');

        $this->get('http://coppers.localhost/')->assertInertia(fn (Assert $page) => $page->where('newest.0.slug', 'camisa-oxford'));
    }
}
