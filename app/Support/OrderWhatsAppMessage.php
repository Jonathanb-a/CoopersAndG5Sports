<?php

namespace App\Support;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Number;

/**
 * Mensajes prellenados de WhatsApp de un pedido, para escribirle a la tienda del pedido.
 * El pedido debe traer cargados `customer` e `items.productVariant.product` (con borrados).
 */
class OrderWhatsAppMessage
{
    /** Mensaje para mandar el comprobante de pago: pedido, cliente, total, entrega y piezas sobre pedido. */
    public static function receipt(Order $order): string
    {
        $lines = [
            'Hola, les envío el comprobante de pago de mi pedido.',
            '',
            "Pedido: {$order->number}",
            'Nombre: '.($order->customer?->name ?? '—'),
            'Total: '.Number::currency((float) $order->total),
            'Entrega: '.$order->delivery_method->getLabel(),
        ];

        $backorder = $order->items->filter(fn (OrderItem $item) => $item->is_backorder);

        if ($backorder->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Piezas sobre pedido:';

            foreach ($backorder as $item) {
                $days = $item->productVariant?->product?->backorder_days;

                $lines[] = "- {$item->product_name} (talla {$item->size}) × {$item->quantity}: "
                    .($days ? "llega en {$days} días hábiles" : 'tiempo de entrega por confirmar');
            }
        }

        return implode("\n", $lines);
    }

    /** Mensaje corto para preguntar algo del pedido. */
    public static function question(Order $order): string
    {
        return "Hola, tengo una duda sobre mi pedido {$order->number}.";
    }
}
