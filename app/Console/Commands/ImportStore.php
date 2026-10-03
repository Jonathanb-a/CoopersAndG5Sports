<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\Import\InventoryImporter;
use App\Services\Import\InventoryReader;
use Illuminate\Console\Command;
use Throwable;

class ImportStore extends Command
{
    protected $signature = 'store:import
        {store_slug : Slug de la tienda (coopers, g5)}
        {archivo : Ruta del Excel del cliente (o nombre dentro de storage/app/imports)}
        {--dry-run : No escribe nada; solo muestra un resumen por hoja}';

    protected $description = 'Importa el inventario de un Excel del cliente a una tienda';

    public function handle(InventoryReader $reader, InventoryImporter $importer): int
    {
        $store = Store::where('slug', $this->argument('store_slug'))->first();
        if ($store === null) {
            $this->error("No existe la tienda «{$this->argument('store_slug')}».");

            return self::FAILURE;
        }

        $path = $this->resolvePath($this->argument('archivo'));
        if ($path === null) {
            $this->error("No se encontró el archivo «{$this->argument('archivo')}».");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ((int) ini_get('memory_limit') !== -1 && $this->memoryLimitMb() < 1024) {
            ini_set('memory_limit', '1024M');
        }

        $this->line('Tienda:  '.$store->name.' ('.$store->slug.')');
        $this->line('Archivo: '.$path);
        $this->line('Modo:    '.($dryRun ? 'SIMULACIÓN (no se escribe nada)' : 'IMPORTACIÓN REAL'));
        $this->newLine();

        try {
            ['sheets' => $sheets, 'ignored' => $ignored] = $reader->read($path);
            $results = $importer->run($store, $sheets, $dryRun);
        } catch (Throwable $exception) {
            $this->error('La importación falló y no se guardó nada: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($ignored !== []) {
            $this->line('Hojas ignoradas (sin «Nombre del Producto»): '.implode(', ', $ignored));
            $this->newLine();
        }

        $rows = [];
        $totals = ['products' => 0, 'variants' => 0, 'pieces' => 0, 'warnings' => 0];

        foreach ($sheets as $sheet) {
            $result = $results[$sheet->title];

            $rows[] = [
                $sheet->title,
                $sheet->sizeType->value,
                count($sheet->products),
                $sheet->variants(),
                $sheet->pieces(),
                count($sheet->warnings),
                $result['products_existing'],
            ];

            $totals['products'] += count($sheet->products);
            $totals['variants'] += $sheet->variants();
            $totals['pieces'] += $sheet->pieces();
            $totals['warnings'] += count($sheet->warnings);
        }

        $this->table(
            ['Hoja', 'Tipo de talla', 'Productos', 'Variantes', 'Piezas', 'Advertencias', 'Productos que ya existen'],
            [...$rows, ['TOTAL', '', $totals['products'], $totals['variants'], $totals['pieces'], $totals['warnings'], array_sum(array_column($results, 'products_existing'))]],
        );

        foreach ($sheets as $sheet) {
            if ($sheet->warnings === []) {
                continue;
            }

            $this->newLine();
            $this->warn("Advertencias · {$sheet->title}");
            foreach ($sheet->warnings as $warning) {
                $this->line('  - '.$warning);
            }
        }

        $this->newLine();

        $new = [
            'products' => array_sum(array_column($results, 'products_new')),
            'variants' => array_sum(array_column($results, 'variants_new')),
            'pieces' => array_sum(array_column($results, 'pieces_new')),
            'images' => array_sum(array_column($results, 'images')),
        ];

        if ($dryRun) {
            $this->info("Simulación terminada. No se escribió nada. Se crearían {$new['products']} producto(s), {$new['variants']} variante(s) y {$new['pieces']} pieza(s).");
        } else {
            $this->info("Importación terminada: {$new['products']} producto(s), {$new['variants']} variante(s), {$new['pieces']} pieza(s) y {$new['images']} foto(s) nuevos.");
        }

        return self::SUCCESS;
    }

    private function resolvePath(string $file): ?string
    {
        foreach ([$file, storage_path('app/imports/'.$file), base_path($file)] as $candidate) {
            if (is_file($candidate)) {
                return realpath($candidate);
            }
        }

        return null;
    }

    private function memoryLimitMb(): int
    {
        $limit = trim((string) ini_get('memory_limit'));
        $value = (int) $limit;

        return match (strtoupper(substr($limit, -1))) {
            'G' => $value * 1024,
            'K' => intdiv($value, 1024),
            'M' => $value,
            default => intdiv($value, 1048576),
        };
    }
}
