<?php

namespace Tests\Feature;

use App\Enums\SizeType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Support\ProductCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Datos de la tarjeta de producto: la segunda foto sirve al cambio al pasar el cursor (animaciones de Copper's). */
class ProductCardTest extends TestCase
{
    use RefreshDatabase;

    private function product(int $images): Product
    {
        $store = Store::create(['name' => "Copper's", 'slug' => 'coppers', 'domain' => 'coppers.localhost']);
        $category = Category::create(['store_id' => $store->id, 'name' => 'General', 'slug' => 'general', 'size_type' => SizeType::OneSize]);
        $product = Product::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => 'Camisa', 'slug' => 'camisa', 'price' => 500]);

        foreach (range(1, $images) as $position) {
            $product->images()->create(['path' => "products/foto-{$position}.jpg", 'position' => $position]);
        }

        return $product->load('images')->loadSum('variants as total_stock', 'stock');
    }

    public function test_the_second_photo_is_the_next_one_by_position()
    {
        $card = ProductCard::from($this->product(3));

        $this->assertStringEndsWith('products/foto-1.jpg', $card['image_url']);
        $this->assertStringEndsWith('products/foto-2.jpg', $card['second_image_url']);
    }

    public function test_without_a_second_photo_it_is_null()
    {
        $one = ProductCard::from($this->product(1));

        $this->assertNotNull($one['image_url']);
        $this->assertNull($one['second_image_url']);
    }

    public function test_without_photos_both_are_null()
    {
        $card = ProductCard::from(Product::query()->make(['name' => 'Sin foto', 'slug' => 'sin-foto', 'price' => 1])->setRelation('images', collect()));

        $this->assertNull($card['image_url']);
        $this->assertNull($card['second_image_url']);
    }
}
