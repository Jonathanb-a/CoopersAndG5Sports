<?php

namespace App\Services\Import;

use App\Enums\SizeType;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\BaseDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Lee el Excel del cliente. Cada hoja con el encabezado «Nombre del Producto» es una
 * categoría; las demás se ignoran.
 */
class InventoryReader
{
    /** Talla de las filas que vienen sin talla. */
    public const NO_SIZE = 'Única';

    private const BRANDS = ['NIKE', 'ADIDAS', 'ON', 'PUMA'];

    private const HEADER_SCAN_ROWS = 15;

    /**
     * @return array{sheets: list<ImportedSheet>, ignored: list<string>}
     */
    public function read(string $path): array
    {
        $workbook = IOFactory::createReaderForFile($path)->load($path);

        $sheets = [];
        $ignored = [];
        /** @var array<string, array{ImportedProduct, ImportedSheet}> $seen */
        $seen = [];

        foreach ($workbook->getAllSheets() as $worksheet) {
            $title = $this->text($worksheet->getTitle());
            $sheet = $this->readSheet($worksheet, $title);

            if ($sheet === null) {
                $ignored[] = $title;

                continue;
            }

            // Un mismo producto en dos hojas: se fusiona con el de la primera hoja.
            foreach ($sheet->products as $slug => $product) {
                if (isset($seen[$slug])) {
                    [$first, $firstSheet] = $seen[$slug];
                    $first->merge($product);
                    unset($sheet->products[$slug]);
                    $sheet->warnings[] = "«{$product->name}» también está en la hoja «{$firstSheet->title}»: se fusionó allí.";

                    continue;
                }

                $seen[$slug] = [$product, $sheet];
            }

            $sheets[] = $sheet;
        }

        foreach ($sheets as $sheet) {
            foreach ($sheet->products as $product) {
                $this->productWarnings($sheet, $product);
            }
        }

        return ['sheets' => $sheets, 'ignored' => $ignored];
    }

    private function readSheet(Worksheet $ws, string $title): ?ImportedSheet
    {
        [$headerRow, $columns] = $this->findHeader($ws);

        if ($headerRow === null) {
            return null;
        }

        $sheet = new ImportedSheet($title, Str::slug($title), SizeType::Clothing);

        $missing = array_diff(['name', 'size', 'stock', 'cost', 'price'], array_keys($columns));
        if ($missing !== []) {
            $sheet->warnings[] = 'Hoja omitida: faltan las columnas '.implode(', ', $missing).'.';

            return $sheet;
        }

        $images = $this->imagesByRow($ws, $columns['photo'] ?? 1);
        $datesAsSizes = 0;
        $emptySizes = 0;
        $rows = 0;

        for ($row = $headerRow + 1, $last = $ws->getHighestDataRow(); $row <= $last; $row++) {
            $name = $this->text($this->value($ws, $columns['name'], $row));

            // Vacía o fila de sección (texto solo en la primera columna).
            if ($name === '') {
                continue;
            }

            // Fila de totales.
            if (preg_match('/^totales?$/iu', $name)) {
                continue;
            }

            $stockValue = $this->value($ws, $columns['stock'], $row);
            $stock = $this->parseNumber($stockValue);
            if ($stock === null || $stock < 0) {
                $sheet->warnings[] = "Fila {$row} («{$name}»): existencia inválida (".json_encode($stockValue, JSON_UNESCAPED_UNICODE).'); se omitió.';

                continue;
            }
            if ($stock != (int) $stock) {
                $sheet->warnings[] = "Fila {$row} («{$name}»): existencia con decimales ({$stock}); se redondeó.";
            }

            [$size, $fromDate] = $this->size($ws->getCell([$columns['size'], $row]));
            $rows++;
            if ($fromDate) {
                $datesAsSizes++;
            }
            if ($size === '') {
                $emptySizes++;
            }

            $slug = Str::slug($name);
            if ($slug === '') {
                $sheet->warnings[] = "Fila {$row} («{$name}»): el nombre no genera un slug válido; se omitió.";

                continue;
            }

            $product = $sheet->products[$slug] ??= new ImportedProduct($name, $slug, $this->brand($name), $row);
            $product->addRow(
                $row,
                $size === '' ? self::NO_SIZE : $size,
                (int) round($stock),
                $this->parseNumber($this->value($ws, $columns['cost'], $row)),
                $this->parseNumber($this->value($ws, $columns['price'], $row)),
                $images[$row] ?? null,
            );
        }

        if ($rows > 0 && $emptySizes === $rows) {
            $sheet->sizeType = SizeType::OneSize;
        } elseif (preg_match('/calzado|sandalia/iu', $title)) {
            $sheet->sizeType = SizeType::Footwear;
        }

        if ($emptySizes > 0 && $emptySizes < $rows) {
            $sheet->warnings[] = "{$emptySizes} fila(s) sin talla en una hoja con tallas; se guardaron como «".self::NO_SIZE.'».';
        }

        if ($datesAsSizes > 0) {
            $sheet->warnings[] = "{$datesAsSizes} talla(s) venían como fecha de Excel (formato d.m); se convirtieron a talla (6 may = «6.5»).";
        }

        return $sheet;
    }

    private function productWarnings(ImportedSheet $sheet, ImportedProduct $product): void
    {
        foreach ($product->sizeRows as $size => $rows) {
            if (count($rows) > 1) {
                $sheet->warnings[] = "«{$product->name}»: la talla {$size} aparece en ".count($rows).' filas ('.implode(', ', $rows).'); se sumó el stock.';
            }
        }

        $prices = array_values(array_unique($product->prices));
        if (count($prices) > 1) {
            $sheet->warnings[] = "«{$product->name}»: precio de venta distinto entre filas (".implode(', ', array_map($this->money(...), $prices)).'); se usó '.$this->money($product->price()).'.';
        }

        $costs = array_values(array_unique($product->costs));
        if (count($costs) > 1) {
            $sheet->warnings[] = "«{$product->name}»: precio de compra distinto entre filas (".implode(', ', array_map($this->money(...), $costs)).'); se usó '.$this->money($product->cost()).'.';
        }

        if ($product->prices === []) {
            $sheet->warnings[] = "«{$product->name}»: sin precio de venta; se guardó en 0.";
        }

        if ($product->image === null) {
            $sheet->warnings[] = "«{$product->name}» (fila {$product->firstRow}): sin foto.";
        }
    }

    /**
     * @return array{?int, array<string, int>} fila del encabezado y columnas (1-based) por clave
     */
    private function findHeader(Worksheet $ws): array
    {
        $lastColumn = min(Coordinate::columnIndexFromString($ws->getHighestDataColumn()), 30);

        for ($row = 1, $max = min(self::HEADER_SCAN_ROWS, $ws->getHighestDataRow()); $row <= $max; $row++) {
            $columns = [];
            $found = false;

            for ($column = 1; $column <= $lastColumn; $column++) {
                $label = $this->label($this->value($ws, $column, $row));

                $key = match (true) {
                    $label === 'nombre del producto' => 'name',
                    $label === 'foto' => 'photo',
                    $label === 'talla' => 'size',
                    str_starts_with($label, 'existencia') => 'stock',
                    str_starts_with($label, 'precio compra') => 'cost',
                    str_starts_with($label, 'precio venta') => 'price',
                    default => null,
                };

                if ($key !== null) {
                    $columns[$key] ??= $column;
                    $found = $found || $key === 'name';
                }
            }

            if ($found) {
                return [$row, $columns];
            }
        }

        return [null, []];
    }

    /**
     * Imagen anclada a cada fila (la de la columna FOTO si hay varias).
     *
     * @return array<int, BaseDrawing>
     */
    private function imagesByRow(Worksheet $ws, int $photoColumn): array
    {
        $candidates = [];

        foreach ($ws->getDrawingCollection() as $order => $drawing) {
            [$letters, $row] = Coordinate::coordinateFromString($drawing->getCoordinates());
            $column = Coordinate::columnIndexFromString($letters);

            $candidates[(int) $row][] = [abs($column - $photoColumn), (int) $drawing->getOffsetY(), $order, $drawing];
        }

        $images = [];
        foreach ($candidates as $row => $list) {
            usort($list, fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
            $images[$row] = $list[0][3];
        }

        return $images;
    }

    /** @return array{string, bool} talla normalizada y si venía como fecha de Excel */
    private function size(Cell $cell): array
    {
        $value = $this->cellValue($cell);

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return ['', false];
        }

        if (is_numeric($value)) {
            // «6.5» tecleado en un Excel con coma decimal se guarda como la fecha 6-may.
            if (Date::isDateTime($cell)) {
                $date = Date::excelToDateTimeObject((float) $value);

                return [$date->format('j').'.'.$date->format('n'), true];
            }

            return [$this->number((float) $value), false];
        }

        $size = $this->text((string) $value);

        if (preg_match('/^\d+,\d+$/', $size)) {
            $size = str_replace(',', '.', $size);
        }

        return [mb_strtoupper($size), false];
    }

    private function brand(string $name): ?string
    {
        $first = mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+$/u', '', explode(' ', $name)[0]));

        return in_array($first, self::BRANDS, true) ? ucfirst(strtolower($first)) : null;
    }

    private function value(Worksheet $ws, int $column, int $row): mixed
    {
        return $this->cellValue($ws->getCell([$column, $row]));
    }

    private function cellValue(Cell $cell): mixed
    {
        $value = $cell->getValue();

        if ($value instanceof RichText) {
            return $value->getPlainText();
        }

        if (is_string($value) && str_starts_with($value, '=')) {
            try {
                return $cell->getCalculatedValue();
            } catch (Throwable) {
                return null;
            }
        }

        return $value;
    }

    /** Quita saltos de línea, espacios dobles y espacios duros. */
    private function text(mixed $value): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $value));
    }

    private function label(mixed $value): string
    {
        return mb_strtolower(Str::ascii($this->text($value)));
    }

    private function parseNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $clean = preg_replace('/[^\d.,-]/', '', (string) $value);
        if ($clean === '' || $clean === '-') {
            return null;
        }

        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace(',', '', $clean);
        } elseif (preg_match('/^-?\d+,\d{1,2}$/', $clean)) {
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function money(?float $value): string
    {
        return '$'.number_format((float) $value, 2);
    }
}
