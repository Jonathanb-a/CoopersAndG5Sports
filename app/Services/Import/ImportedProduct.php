<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Worksheet\BaseDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

/**
 * Un producto del Excel: todas las filas con el mismo nombre dentro de una hoja.
 * Cada talla es una variante; si una talla se repite se suma el stock.
 */
class ImportedProduct
{
    /** @var array<string, int> talla => stock */
    public array $sizes = [];

    /** @var array<string, list<int>> talla => filas del Excel */
    public array $sizeRows = [];

    /** @var list<float> */
    public array $prices = [];

    /** @var list<float> */
    public array $costs = [];

    public ?BaseDrawing $image = null;

    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly ?string $brand,
        public readonly int $firstRow,
    ) {}

    public function addRow(int $row, string $size, int $stock, ?float $cost, ?float $price, ?BaseDrawing $image): void
    {
        $this->sizes[$size] = ($this->sizes[$size] ?? 0) + $stock;
        $this->sizeRows[$size][] = $row;

        if ($price !== null) {
            $this->prices[] = $price;
        }

        if ($cost !== null) {
            $this->costs[] = $cost;
        }

        // Una foto por producto: la de su primera fila que la tenga.
        $this->image ??= $image;
    }

    /** Precio de venta: el más alto entre las filas. */
    public function price(): float
    {
        return $this->prices === [] ? 0.0 : max($this->prices);
    }

    /** Precio de compra: el más alto entre las filas. */
    public function cost(): ?float
    {
        return $this->costs === [] ? null : max($this->costs);
    }

    public function pieces(): int
    {
        return array_sum($this->sizes);
    }

    /** Absorbe las filas de otro producto con el mismo slug (hoja distinta). */
    public function merge(self $other): void
    {
        foreach ($other->sizes as $size => $stock) {
            $this->sizes[$size] = ($this->sizes[$size] ?? 0) + $stock;
            $this->sizeRows[$size] = [...($this->sizeRows[$size] ?? []), ...$other->sizeRows[$size]];
        }

        $this->prices = [...$this->prices, ...$other->prices];
        $this->costs = [...$this->costs, ...$other->costs];
        $this->image ??= $other->image;
    }

    public function imageBytes(): ?string
    {
        if ($this->image instanceof Drawing) {
            $bytes = @file_get_contents($this->image->getPath());

            return $bytes === false ? null : $bytes;
        }

        if ($this->image instanceof MemoryDrawing) {
            ob_start();
            call_user_func($this->image->getRenderingFunction(), $this->image->getImageResource());

            return ob_get_clean() ?: null;
        }

        return null;
    }

    public function imageExtension(): string
    {
        $extension = $this->image instanceof Drawing ? strtolower($this->image->getExtension()) : 'png';

        return $extension === 'jpeg' ? 'jpg' : ($extension ?: 'png');
    }
}
