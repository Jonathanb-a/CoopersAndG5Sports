<?php

namespace App\Services\Import;

use App\Enums\SizeType;

/** Una hoja importable del Excel = una categoría. */
class ImportedSheet
{
    /** @var array<string, ImportedProduct> slug => producto */
    public array $products = [];

    /** @var list<string> */
    public array $warnings = [];

    public function __construct(
        public readonly string $title,
        public readonly string $categorySlug,
        public SizeType $sizeType,
    ) {}

    public function variants(): int
    {
        return array_sum(array_map(fn (ImportedProduct $product) => count($product->sizes), $this->products));
    }

    public function pieces(): int
    {
        return array_sum(array_map(fn (ImportedProduct $product) => $product->pieces(), $this->products));
    }
}
