<?php

namespace Tests\Feature\Admin;

use App\Enums\SizeType;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Services\StockService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockPanelTest extends TestCase
{
    use RefreshDatabase;

    private Store $coopers;

    private Store $g5;

    private Category $g5Category;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coopers = Store::create(['name' => 'Coopers', 'slug' => 'coopers', 'domain' => 'coopers.localhost']);
        $this->g5 = Store::create(['name' => 'G5 Esports', 'slug' => 'g5', 'domain' => 'g5.localhost']);
        $this->g5Category = Category::create(['store_id' => $this->g5->id, 'name' => 'Calzado', 'slug' => 'calzado', 'size_type' => SizeType::Footwear]);
        $this->owner = User::factory()->create(['role' => UserRole::Owner]);
    }

    private function panel(Store $store, ?User $user = null): void
    {
        $this->actingAs($user ?? $this->owner);
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
        Filament::setTenant($store);
    }

    /** @param array<string, int> $sizes talla => stock */
    private function product(Store $store, string $name, array $sizes, ?Category $category = null): Product
    {
        $category ??= Category::firstOrCreate(
            ['store_id' => $store->id, 'slug' => 'general'],
            ['name' => 'General', 'size_type' => SizeType::OneSize],
        );

        $product = Product::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => $name,
            'slug' => str($name)->slug(),
            'price' => 100,
        ]);

        foreach ($sizes as $size => $stock) {
            $product->variants()->create(['size' => (string) $size, 'stock' => $stock]);
        }

        return $product;
    }

    private function adjust(ProductVariant $variant): TestAction
    {
        return TestAction::make('adjustStock')
            ->schemaComponent('variants')
            ->arguments(['item' => "record-{$variant->id}"]);
    }

    public function test_initial_stock_on_create_is_recorded_as_one_entry_per_size()
    {
        $this->panel($this->g5);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Tenis Predator',
                'color' => 'Negro',
                'category_id' => $this->g5Category->id,
                'price' => 1999,
                'variants' => [
                    ['size' => '26', 'stock' => 4],
                    ['size' => '27', 'stock' => 0],
                    ['size' => '28', 'stock' => 7],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::withoutGlobalScopes()->where('slug', 'tenis-predator-negro')->firstOrFail();

        $this->assertSame(['26' => 4, '27' => 0, '28' => 7], $product->variants->pluck('stock', 'size')->all());

        $movements = StockMovement::with('productVariant')->orderBy('id')->get();
        $this->assertCount(2, $movements);
        $this->assertSame(['26', '28'], $movements->map->productVariant->pluck('size')->all());
        $this->assertSame([4, 7], $movements->pluck('quantity')->all());
        $this->assertTrue($movements->every(fn ($m) => $m->type === StockMovementType::Entry && $m->user_id === $this->owner->id));
    }

    public function test_adjust_stock_action_adds_an_entry_from_the_edit_page()
    {
        $product = $this->product($this->g5, 'Jersey', ['M' => 3]);
        $variant = $product->variants->first();
        $this->panel($this->g5);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction($this->adjust($variant), ['type' => 'entrada', 'quantity' => 5, 'notes' => 'Llegó pedido'])
            ->assertHasNoFormErrors()
            ->assertNotified('Stock actualizado');

        $this->assertSame(8, $variant->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $variant->id,
            'type' => 'entrada',
            'quantity' => 5,
            'user_id' => $this->owner->id,
            'notes' => 'Llegó pedido',
        ]);
    }

    public function test_adjust_stock_action_rejects_an_adjustment_that_would_go_negative()
    {
        $product = $this->product($this->g5, 'Jersey', ['M' => 3]);
        $variant = $product->variants->first();
        $this->panel($this->g5);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction($this->adjust($variant), ['type' => 'ajuste', 'quantity' => -4])
            ->assertNotified('No se pudo ajustar el stock');

        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_adjust_stock_action_validates_the_quantity()
    {
        $product = $this->product($this->g5, 'Jersey', ['M' => 3]);
        $variant = $product->variants->first();
        $this->panel($this->g5);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction($this->adjust($variant), ['type' => 'entrada', 'quantity' => -2])
            ->assertHasFormErrors(['quantity']);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->callAction($this->adjust($variant), ['type' => 'ajuste', 'quantity' => 0])
            ->assertHasFormErrors(['quantity']);

        $this->assertSame(3, $variant->fresh()->stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_edit_page_shows_a_traffic_light_badge_per_size()
    {
        $product = $this->product($this->g5, 'Tenis', ['26' => 0, '27' => 1, '28' => 2]);
        $this->panel($this->g5);

        Livewire::test(EditProduct::class, ['record' => $product->getRouteKey()])
            ->assertSee('Agotado')
            ->assertSee('Poco stock')
            ->assertSee('Disponible')
            ->assertSeeHtml('fi-color-danger')
            ->assertSeeHtml('fi-color-warning')
            ->assertSeeHtml('fi-color-success');
    }

    public function test_product_table_traffic_light_and_stock_filters()
    {
        $out = $this->product($this->g5, 'Agotado', ['M' => 0, 'L' => 0]);
        $last = $this->product($this->g5, 'Ultima', ['M' => 1, 'L' => 0]);
        $low = $this->product($this->g5, 'Poco', ['M' => 1, 'L' => 1]);
        $ok = $this->product($this->g5, 'Bastante', ['M' => 2, 'L' => 1]);
        $this->panel($this->g5);

        $component = Livewire::test(ListProducts::class);
        $column = $component->instance()->getTable()->getColumn('variants_sum_stock');

        foreach ([[$out, 'danger'], [$last, 'warning'], [$low, 'warning'], [$ok, 'success']] as [$product, $color]) {
            $record = Product::withSum('variants', 'stock')->find($product->id);
            $this->assertSame($color, $column->record($record)->getColor($record->variants_sum_stock), $product->name);
        }

        $component
            ->assertSeeHtml('fi-color-danger')
            ->assertSeeHtml('fi-color-warning')
            ->assertSeeHtml('fi-color-success')
            ->filterTable('sold_out')
            ->assertCanSeeTableRecords([$out])
            ->assertCanNotSeeTableRecords([$last, $low, $ok])
            ->resetTableFilters()
            ->filterTable('last_piece')
            ->assertCanSeeTableRecords([$last])
            ->assertCanNotSeeTableRecords([$out, $low, $ok]);
    }

    public function test_movements_resource_is_read_only_scoped_to_the_store_and_filterable()
    {
        $service = app(StockService::class);

        $mine = $this->product($this->g5, 'Mio', ['M' => 0]);
        $theirs = $this->product($this->coopers, 'Ajeno', ['M' => 0]);
        $trashed = $this->product($this->g5, 'Borrado', ['M' => 0]);

        $entry = $service->change($mine->variants->first(), StockMovementType::Entry, 5, $this->owner, 'Compra');
        $adjustment = $service->change($mine->variants->first(), StockMovementType::Adjustment, -1, $this->owner);
        $other = $service->change($theirs->variants->first(), StockMovementType::Entry, 9, $this->owner);
        $old = $service->change($trashed->variants->first(), StockMovementType::Entry, 2, $this->owner);
        $old->forceFill(['created_at' => '2026-01-10 10:00:00'])->save();
        $trashed->delete();

        $this->panel($this->g5);

        Livewire::test(ListStockMovements::class)
            ->assertCanSeeTableRecords([$entry, $adjustment, $old])
            ->assertCanNotSeeTableRecords([$other])
            ->assertTableColumnStateSet('productVariant.product.name', 'Borrado', $old)
            ->assertTableColumnStateSet('productVariant.size', 'M', $entry)
            ->assertTableColumnStateSet('user.name', $this->owner->name, $entry)
            ->assertTableColumnStateSet('notes', 'Compra', $entry)
            ->filterTable('type', StockMovementType::Adjustment->value)
            ->assertCanSeeTableRecords([$adjustment])
            ->assertCanNotSeeTableRecords([$entry, $old])
            ->resetTableFilters()
            ->filterTable('created_at', ['from' => '2026-01-01', 'until' => '2026-01-31'])
            ->assertCanSeeTableRecords([$old])
            ->assertCanNotSeeTableRecords([$entry, $adjustment]);

        $this->assertFalse(StockMovementResource::canCreate());
        $this->assertSame(['index'], array_keys(StockMovementResource::getPages()));
    }

    public function test_staff_can_open_the_movements_page_of_their_store()
    {
        $staff = User::factory()->create(['role' => UserRole::Staff]);
        $staff->stores()->attach($this->g5->id);

        $this->actingAs($staff)->get('/admin/g5/stock-movements')->assertOk();
        $this->get('/admin/coopers/stock-movements')->assertNotFound();
    }
}
