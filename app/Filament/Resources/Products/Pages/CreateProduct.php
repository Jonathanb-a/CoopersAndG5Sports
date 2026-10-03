<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\StockMovementType;
use App\Filament\Resources\Products\ProductResource;
use App\Services\StockService;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Las tallas se crean con stock 0; el stock inicial capturado en el formulario
     * entra como un movimiento de entrada por talla, igual que cualquier otro cambio.
     */
    protected function afterCreate(): void
    {
        $stock = app(StockService::class);

        foreach ($this->data['variants'] ?? [] as $item) {
            $initial = (int) ($item['stock'] ?? 0);

            if ($initial <= 0) {
                continue;
            }

            $variant = $this->record->variants()->where('size', $item['size'])->first();

            $stock->change($variant, StockMovementType::Entry, $initial, auth()->user(), 'Stock inicial');
        }
    }
}
