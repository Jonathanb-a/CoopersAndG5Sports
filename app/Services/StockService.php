<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\InsufficientStockException;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockService
{
    /**
     * Único punto de entrada para cambiar el stock de una variante.
     *
     * $quantity es el cambio con signo (positivo suma, negativo resta).
     * Todo ocurre en una transacción con la fila bloqueada; si el stock
     * quedaría negativo se lanza InsufficientStockException y no cambia nada.
     */
    public function change(
        ProductVariant $variant,
        StockMovementType $type,
        int $quantity,
        ?User $user = null,
        ?string $notes = null,
    ): StockMovement {
        if ($quantity === 0) {
            throw new InvalidArgumentException('La cantidad del movimiento no puede ser cero.');
        }

        $movement = DB::transaction(function () use ($variant, $type, $quantity, $user, $notes) {
            $locked = ProductVariant::query()->lockForUpdate()->findOrFail($variant->getKey());

            $newStock = $locked->stock + $quantity;

            if ($newStock < 0) {
                throw InsufficientStockException::for($locked->stock, $quantity);
            }

            $locked->stock = $newStock;
            $locked->save();

            return StockMovement::create([
                'product_variant_id' => $locked->id,
                'type' => $type,
                'quantity' => $quantity,
                'user_id' => $user?->getKey(),
                'notes' => $notes,
            ]);
        });

        $variant->refresh();

        return $movement;
    }
}
