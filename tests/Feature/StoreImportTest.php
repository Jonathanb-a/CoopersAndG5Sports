<?php

namespace Tests\Feature;

use App\Enums\SizeType;
use App\Enums\StockMovementType;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Store;
use DateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class StoreImportTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private string $png;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Store::create(['name' => 'Sports G5', 'slug' => 'g5', 'domain' => 'g5.localhost']);

        $this->png = sys_get_temp_dir().'/import-test-'.uniqid().'.png';
        $image = imagecreatetruecolor(8, 8);
        imagepng($image, $this->png);

        $this->file = sys_get_temp_dir().'/import-test-'.uniqid().'.xlsx';
        $this->buildWorkbook()->save($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @unlink($this->png);

        parent::tearDown();
    }

    private function buildWorkbook(): Xlsx
    {
        $book = new Spreadsheet;

        // Hoja sin el encabezado «Nombre del Producto»: se ignora.
        $book->getActiveSheet()->setTitle('Panel de Control')->setCellValue('A1', 'RESUMEN');

        $calzado = $book->createSheet()->setTitle('Calzado ');
        $this->header($calzado);
        $calzado->setCellValue('A5', 'TACOS DE FUTBOL'); // fila de sección
        $this->row($calzado, 6, "NIKE  PHANTOM\nROSAS ", 6.5, 1, '$900.00', 1500);
        $this->row($calzado, 7, 'NIKE PHANTOM ROSAS', '7,5', 2, 900, 1700);
        $this->row($calzado, 8, 'NIKE PHANTOM ROSAS', 7.5, 1, 900, 1700); // talla repetida: suma
        // Talla «6.5» tecleada como fecha 6-may con formato d.m
        $this->row($calzado, 9, 'ADIDAS PREDATOR', Date::PHPToExcel(new DateTime('2026-05-06')), 3, 800, 1600);
        $calzado->getStyle('D9')->getNumberFormat()->setFormatCode('d.m');
        $this->row($calzado, 10, 'ADIDAS PREDATOR', 8, 0, 800, 1600);
        $this->row($calzado, 11, 'TOTALES', null, '=SUM(E6:E10)', null, null); // se salta

        $gorras = $book->createSheet()->setTitle('Gorras');
        $this->header($gorras);
        $this->row($gorras, 5, 'Scream Hats', null, 4, 400, 650);

        foreach ([[$calzado, 6], [$calzado, 9], [$gorras, 5]] as [$sheet, $row]) {
            $drawing = new Drawing;
            $drawing->setPath($this->png);
            $drawing->setCoordinates("A{$row}");
            $drawing->setWorksheet($sheet);
        }

        return new Xlsx($book);
    }

    private function header(Worksheet $sheet): void
    {
        foreach (['FOTO', 'Nombre del Producto', 'Categoría', 'Talla', 'Existencia (Stock)', 'Stock Mínimo', 'Precio Compra ($)', 'Precio Venta ($)'] as $i => $label) {
            $sheet->setCellValue([$i + 1, 4], $label);
        }
    }

    private function row(Worksheet $sheet, int $row, string $name, mixed $size, mixed $stock, mixed $cost, mixed $price): void
    {
        $sheet->setCellValue("B{$row}", $name);
        if ($size !== null) {
            $sheet->setCellValue("D{$row}", $size);
        }
        $sheet->setCellValue("E{$row}", $stock);
        $sheet->setCellValue("G{$row}", $cost);
        $sheet->setCellValue("H{$row}", $price);
    }

    public function test_dry_run_writes_nothing_and_shows_a_summary()
    {
        $this->artisan('store:import', ['store_slug' => 'g5', 'archivo' => $this->file, '--dry-run' => true])
            ->expectsOutputToContain('Hojas ignoradas (sin «Nombre del Producto»): Panel de Control')
            ->expectsOutputToContain('Simulación terminada. No se escribió nada. Se crearían 3 producto(s), 5 variante(s) y 11 pieza(s).')
            ->assertSuccessful();

        $this->assertSame(0, Product::withoutGlobalScopes()->count());
        $this->assertSame(0, Category::withoutGlobalScopes()->count());
        $this->assertSame(0, StockMovement::count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_import_applies_the_normalization_rules()
    {
        $this->artisan('store:import', ['store_slug' => 'g5', 'archivo' => $this->file])->assertSuccessful();

        // Categorías = nombre de la hoja; talla única si la hoja no trae tallas.
        $this->assertSame(SizeType::Footwear, Category::withoutGlobalScopes()->where('slug', 'calzado')->first()->size_type);
        $this->assertSame(SizeType::OneSize, Category::withoutGlobalScopes()->where('slug', 'gorras')->first()->size_type);
        $this->assertSame('Calzado', Category::withoutGlobalScopes()->where('slug', 'calzado')->first()->name);

        // Nombre normalizado, marca por primera palabra, precio y costo más altos.
        $phantom = Product::withoutGlobalScopes()->where('slug', 'nike-phantom-rosas')->firstOrFail();
        $this->assertSame('NIKE PHANTOM ROSAS', $phantom->name);
        $this->assertSame('Nike', $phantom->brand->name);
        $this->assertSame('1700.00', $phantom->price);
        $this->assertSame('900.00', $phantom->cost);

        // «6,5» y 6.5 → "6.5"; talla repetida (7,5 y 7.5) suma el stock.
        $this->assertSame(['6.5' => 1, '7.5' => 3], $phantom->variants->pluck('stock', 'size')->all());

        // La fecha 6-may con formato d.m es la talla 6.5; una talla con 0 piezas no genera movimiento.
        $predator = Product::withoutGlobalScopes()->where('slug', 'adidas-predator')->firstOrFail();
        $this->assertSame(['6.5' => 3, '8' => 0], $predator->variants->pluck('stock', 'size')->all());
        $this->assertSame('Adidas', $predator->brand->name);

        // Fila sin talla en hoja sin tallas.
        $hat = Product::withoutGlobalScopes()->where('slug', 'scream-hats')->firstOrFail();
        $this->assertNull($hat->brand_id);
        $this->assertSame(['Única' => 4], $hat->variants->pluck('stock', 'size')->all());

        // TOTALES y secciones no son productos.
        $this->assertSame(3, Product::withoutGlobalScopes()->count());

        // El stock entra por StockService como «entrada».
        $this->assertSame(11, (int) ProductVariant::sum('stock'));
        $this->assertSame(4, StockMovement::count());
        $this->assertSame(11, (int) StockMovement::sum('quantity'));
        $this->assertTrue(StockMovement::all()->every(fn ($m) => $m->type === StockMovementType::Entry && $m->notes === 'Importación de Excel'));

        // Una foto por producto en el disco public (solo los productos con imagen en el Excel).
        $this->assertSame(3, ProductImage::count());
        foreach (ProductImage::all() as $image) {
            Storage::disk('public')->assertExists($image->path);
        }
    }

    public function test_running_it_twice_does_not_duplicate_anything()
    {
        $this->artisan('store:import', ['store_slug' => 'g5', 'archivo' => $this->file])->assertSuccessful();

        $snapshot = fn () => [
            Product::withoutGlobalScopes()->count(),
            ProductVariant::count(),
            (int) ProductVariant::sum('stock'),
            StockMovement::count(),
            ProductImage::count(),
            Category::withoutGlobalScopes()->count(),
            count(Storage::disk('public')->allFiles()),
        ];
        $before = $snapshot();

        $this->artisan('store:import', ['store_slug' => 'g5', 'archivo' => $this->file])
            ->expectsOutputToContain('Importación terminada: 0 producto(s), 0 variante(s), 0 pieza(s) y 0 foto(s) nuevos.')
            ->assertSuccessful();

        $this->assertSame($before, $snapshot());
    }

    public function test_it_fails_cleanly_for_an_unknown_store_or_file()
    {
        $this->artisan('store:import', ['store_slug' => 'nada', 'archivo' => $this->file])
            ->expectsOutputToContain('No existe la tienda')
            ->assertFailed();

        $this->artisan('store:import', ['store_slug' => 'g5', 'archivo' => 'no-existe.xlsx'])
            ->expectsOutputToContain('No se encontró el archivo')
            ->assertFailed();
    }
}
