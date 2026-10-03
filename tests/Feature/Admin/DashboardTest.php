<?php

namespace Tests\Feature\Admin;

use App\Enums\SizeType;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Filament\Widgets\InventoryStats;
use App\Filament\Widgets\LatestStockMovements;
use App\Filament\Widgets\PiecesByCategoryChart;
use App\Filament\Widgets\RestockTable;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\InventoryReport;
use App\Services\StockService;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Store $coopers;

    private Store $g5;

    private User $owner;

    private User $staffG5;

    private User $staffCoopers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coopers = Store::create(['name' => 'Coopers', 'slug' => 'coopers', 'domain' => 'coopers.localhost']);
        $this->g5 = Store::create(['name' => 'G5 Esports', 'slug' => 'g5', 'domain' => 'g5.localhost']);

        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
        $this->staffG5 = User::factory()->create(['role' => UserRole::Staff]);
        $this->staffG5->stores()->attach($this->g5->id);
        $this->staffCoopers = User::factory()->create(['role' => UserRole::Staff]);
        $this->staffCoopers->stores()->attach($this->coopers->id);
    }

    private function panelAs(User $user, Store $store): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($store);
    }

    /** @param array<string, int> $sizes talla => stock */
    private function product(Store $store, string $name, array $sizes, array $extra = [], string $category = 'General'): Product
    {
        $category = Category::firstOrCreate(
            ['store_id' => $store->id, 'slug' => str($category)->slug()],
            ['name' => $category, 'size_type' => SizeType::OneSize],
        );

        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
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

    public function test_sold_out_size_notifies_the_stores_users_and_not_the_other_store()
    {
        $product = $this->product($this->g5, 'Tenis Predator', ['26' => 2, '27' => 3]);
        [$small, $large] = [$product->variants()->where('size', '26')->first(), $product->variants()->where('size', '27')->first()];
        $stock = app(StockService::class);

        // Bajar sin llegar a 0 o subir no avisa.
        $stock->change($large, StockMovementType::Adjustment, -1, $this->staffG5);
        $stock->change($small, StockMovementType::Entry, 1, $this->staffG5);
        $this->assertSame(0, $this->staffG5->notifications()->count());

        // Dejar una talla en 0 avisa al staff de G5, no al de Coopers.
        $stock->change($small, StockMovementType::Adjustment, -3, $this->staffG5);

        $this->assertSame(1, $this->staffG5->notifications()->count());
        $this->assertSame(0, $this->staffCoopers->notifications()->count());

        $data = $this->staffG5->notifications()->first()->data;
        $this->assertSame('Talla agotada', $data['title']);
        $this->assertStringContainsString('talla 26', $data['body']);
        $this->assertStringContainsString('Tenis Predator', $data['body']);
        $this->assertStringContainsString('G5 Esports', $data['body']);
        $this->assertStringNotContainsString('todo el producto', $data['body']);
        $this->assertStringContainsString("/admin/g5/products/{$product->id}/edit", $data['actions'][0]['url']);

        // El owner ve todas las tiendas, así que también recibe el aviso.
        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_the_notification_says_when_the_whole_product_is_sold_out()
    {
        $product = $this->product($this->g5, 'Tenis Predator', ['26' => 1, '27' => 1]);
        $stock = app(StockService::class);

        $stock->change($product->variants()->where('size', '26')->first(), StockMovementType::Adjustment, -1);
        $stock->change($product->variants()->where('size', '27')->first(), StockMovementType::Adjustment, -1);

        $titles = $this->staffG5->notifications()->orderBy('created_at')->get()->map(fn ($n) => $n->data['title'])->all();
        $this->assertSame(['Talla agotada', 'Producto agotado'], $titles);

        $last = $this->staffG5->notifications()->get()->first(fn ($n) => $n->data['title'] === 'Producto agotado');
        $this->assertStringContainsString('se agotó todo el producto «Tenis Predator»', $last->data['body']);
        $this->assertSame(0, $this->staffCoopers->notifications()->count());
    }

    public function test_the_bell_shows_the_notification_to_the_user()
    {
        $product = $this->product($this->g5, 'Tenis Predator', ['26' => 1]);
        app(StockService::class)->change($product->variants->first(), StockMovementType::Adjustment, -1);

        $this->panelAs($this->staffG5, $this->g5);

        $this->get('/admin/g5')->assertOk()->assertSeeLivewire(DatabaseNotifications::class);

        Livewire::test(DatabaseNotifications::class)
            ->assertSee('Producto agotado')
            ->assertSee('Ver producto');
    }

    public function test_staff_does_not_see_the_inventory_value_cards_but_the_owner_does()
    {
        $this->product($this->g5, 'Tenis', ['26' => 2, '27' => 1], ['price' => 1500, 'cost' => 900]);

        $this->panelAs($this->staffG5, $this->g5);
        Livewire::test(InventoryStats::class)
            ->assertSee('Productos activos')
            ->assertSee('Piezas en existencia')
            ->assertSee('Tallas agotadas')
            ->assertSee('Tallas en última pieza')
            ->assertDontSee('Valor del inventario')
            ->assertDontSee('$4,500.00')
            ->assertDontSee('$2,700.00');

        $this->panelAs($this->owner, $this->g5);
        Livewire::test(InventoryStats::class)
            ->assertSee('Valor del inventario a precio de venta')
            ->assertSee('$4,500.00')
            ->assertSee('Valor del inventario a costo')
            ->assertSee('$2,700.00');
    }

    public function test_summary_is_filtered_by_store_and_ignores_deleted_products()
    {
        // G5: producto activo con una talla agotada y una en última pieza.
        $this->product($this->g5, 'Activo', ['S' => 0, 'M' => 1, 'L' => 5], ['price' => 100, 'cost' => 40]);
        // Inactivo: cuenta en piezas y valor, pero no en las alertas ni en «activos».
        $this->product($this->g5, 'Inactivo', ['M' => 3, 'L' => 0], ['price' => 200, 'is_active' => false]);
        // En la papelera: no cuenta.
        $this->product($this->g5, 'Borrado', ['M' => 10])->delete();
        // Otra tienda: no cuenta.
        $this->product($this->coopers, 'Ajeno', ['M' => 99]);

        $summary = app(InventoryReport::class)->summary($this->g5);

        $this->assertSame([
            'active_products' => 1,
            'pieces' => 9,
            'sold_out_sizes' => 1,
            'last_piece_sizes' => 1,
            'retail_value' => 1200.0,
            'cost_value' => 240.0,
        ], $summary);

        $this->panelAs($this->owner, $this->g5);
        Livewire::test(InventoryStats::class)->assertSee('$1,200.00')->assertSee('$240.00');
    }

    public function test_restock_table_lists_red_and_yellow_products_with_sold_out_first()
    {
        $yellow = $this->product($this->g5, 'Amarillo dos', ['M' => 1, 'L' => 1]);
        $red = $this->product($this->g5, 'Rojo', ['M' => 0, 'L' => 0]);
        $yellowOne = $this->product($this->g5, 'Amarillo uno', ['M' => 1, 'L' => 0]);
        $green = $this->product($this->g5, 'Verde', ['M' => 3]);
        $inactive = $this->product($this->g5, 'Inactivo rojo', ['M' => 0], ['is_active' => false]);
        $other = $this->product($this->coopers, 'Rojo ajeno', ['M' => 0]);

        $this->panelAs($this->staffG5, $this->g5);

        Livewire::test(RestockTable::class)
            ->assertCanSeeTableRecords([$red, $yellowOne, $yellow], inOrder: true)
            ->assertCanNotSeeTableRecords([$green, $inactive, $other])
            ->assertTableColumnStateSet('sold_out_count', 2, $red)
            ->assertTableColumnStateSet('total_stock', 0, $red)
            ->assertTableColumnStateSet('total_stock', 1, $yellowOne)
            ->assertTableColumnStateSet('total_stock', 2, $yellow)
            ->assertSee('Por resurtir')
            ->assertSee('/admin/g5/products/'.$red->id.'/edit', escape: false);
    }

    public function test_latest_movements_shows_the_ten_most_recent_of_the_store()
    {
        $product = $this->product($this->g5, 'Tenis', ['M' => 0]);
        $variant = $product->variants->first();
        $service = app(StockService::class);

        $movements = [];
        for ($i = 1; $i <= 12; $i++) {
            $movement = $service->change($variant, StockMovementType::Entry, $i, $this->owner);
            $movement->forceFill(['created_at' => now()->subHours(13 - $i)])->save();
            $movements[$i] = $movement;
        }

        $theirs = $service->change($this->product($this->coopers, 'Ajeno', ['M' => 0])->variants->first(), StockMovementType::Entry, 5);

        $this->panelAs($this->staffG5, $this->g5);

        $component = Livewire::test(LatestStockMovements::class);

        $this->assertCount(10, $component->instance()->getTableRecords());

        $component
            ->assertCanSeeTableRecords(array_slice($movements, 2, 10), inOrder: false)
            ->assertCanNotSeeTableRecords([$movements[1], $movements[2], $theirs])
            ->assertSee('Últimos movimientos de stock');
    }

    public function test_chart_sums_pieces_per_category_of_the_store()
    {
        $this->product($this->g5, 'Tenis uno', ['26' => 3, '27' => 2], category: 'Calzado');
        $this->product($this->g5, 'Tenis dos', ['26' => 1], category: 'Calzado');
        $this->product($this->g5, 'Jersey', ['M' => 4], category: 'Jerseys');
        $this->product($this->g5, 'Borrado', ['M' => 50], category: 'Jerseys')->delete();
        $this->product($this->coopers, 'Ajeno', ['M' => 99], category: 'Ropa');
        Category::create(['store_id' => $this->g5->id, 'name' => 'Vacía', 'slug' => 'vacia', 'size_type' => SizeType::OneSize]);

        $this->assertSame(['Calzado' => 6, 'Jerseys' => 4], app(InventoryReport::class)->piecesByCategory($this->g5));

        $this->panelAs($this->staffG5, $this->g5);
        $component = Livewire::test(PiecesByCategoryChart::class)->assertSee('Piezas por categoría');

        $method = new ReflectionMethod(PiecesByCategoryChart::class, 'getData');
        $data = $method->invoke($component->instance());

        $this->assertSame(['Calzado', 'Jerseys'], $data['labels']);
        $this->assertSame([6, 4], $data['datasets'][0]['data']);
        $this->assertSame('bar', (new ReflectionMethod(PiecesByCategoryChart::class, 'getType'))->invoke($component->instance()));
    }

    public function test_dashboard_page_renders_the_inventory_widgets()
    {
        foreach ([$this->owner, $this->staffG5] as $user) {
            $this->actingAs($user)
                ->get('/admin/g5')
                ->assertOk()
                ->assertSeeLivewire(InventoryStats::class)
                ->assertSeeLivewire(PiecesByCategoryChart::class)
                ->assertSeeLivewire(RestockTable::class)
                ->assertSeeLivewire(LatestStockMovements::class);
        }
    }
}
