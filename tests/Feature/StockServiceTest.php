<?php

namespace Tests\Feature;

use App\Enums\SizeType;
use App\Enums\StockLevel;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Exceptions\InsufficientStockException;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $store = Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost']);
        $category = Category::create(['store_id' => $store->id, 'name' => 'Calzado', 'slug' => 'calzado', 'size_type' => SizeType::Footwear]);
        $product = Product::create(['store_id' => $store->id, 'category_id' => $category->id, 'name' => 'Tenis', 'slug' => 'tenis', 'price' => 100]);

        $this->variant = $product->variants()->create(['size' => '26', 'stock' => 3]);
    }

    public function test_an_entry_raises_the_stock_and_creates_a_movement()
    {
        $user = User::factory()->create(['role' => UserRole::Staff]);

        $movement = app(StockService::class)->change($this->variant, StockMovementType::Entry, 5, $user, 'Compra a proveedor');

        $this->assertSame(8, $this->variant->stock);
        $this->assertSame(8, $this->variant->fresh()->stock);

        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'product_variant_id' => $this->variant->id,
            'type' => 'entrada',
            'quantity' => 5,
            'user_id' => $user->id,
            'notes' => 'Compra a proveedor',
        ]);
        $this->assertSame(StockMovementType::Entry, $movement->type);
    }

    public function test_an_adjustment_that_would_leave_negative_stock_fails_and_changes_nothing()
    {
        try {
            app(StockService::class)->change($this->variant, StockMovementType::Adjustment, -4);
            $this->fail('Se esperaba InsufficientStockException.');
        } catch (InsufficientStockException $exception) {
            $this->assertStringContainsString('Stock insuficiente', $exception->getMessage());
        }

        $this->assertSame(3, $this->variant->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_an_adjustment_down_to_exactly_zero_is_allowed()
    {
        app(StockService::class)->change($this->variant, StockMovementType::Adjustment, -3);

        $this->assertSame(0, $this->variant->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', ['type' => 'ajuste', 'quantity' => -3]);
    }

    public function test_a_zero_quantity_is_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            app(StockService::class)->change($this->variant, StockMovementType::Entry, 0);
        } finally {
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    /** @return array<string, array{int, string}> */
    public static function variantLevels(): array
    {
        return [
            '0 es rojo' => [0, 'danger'],
            '1 es amarillo' => [1, 'warning'],
            '2 es verde' => [2, 'success'],
            '10 es verde' => [10, 'success'],
        ];
    }

    #[DataProvider('variantLevels')]
    public function test_traffic_light_color_per_size(int $stock, string $color)
    {
        $this->assertSame($color, StockLevel::forVariant($stock)->getColor());
    }

    /** @return array<string, array{int, string}> */
    public static function productLevels(): array
    {
        return [
            '0 es rojo' => [0, 'danger'],
            '1 es amarillo' => [1, 'warning'],
            '2 es amarillo' => [2, 'warning'],
            '3 es verde' => [3, 'success'],
            '50 es verde' => [50, 'success'],
        ];
    }

    #[DataProvider('productLevels')]
    public function test_traffic_light_color_per_product(int $total, string $color)
    {
        $this->assertSame($color, StockLevel::forProduct($total)->getColor());
    }
}
