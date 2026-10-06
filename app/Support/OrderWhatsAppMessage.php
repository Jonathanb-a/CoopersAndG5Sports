<?php

namespace App\Support;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Number;

/**
 * Mensajes prellenados de WhatsApp de un pedido: los que el cliente manda a la tienda (comprobante,
 * dudas) y los avisos que la tienda manda al cliente según el estado.
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

    /**
     * Aviso de la tienda al cliente según el estado del pedido: pago recibido, en camino (con la
     * guía si hay), listo para recoger o entregado. Null si ese estado no lleva aviso.
     * El pedido debe traer `store` y `customer`.
     */
    public static function statusNotice(Order $order): ?string
    {
        $name = trim((string) ($order->customer?->name ?? ($order->shipping_address['name'] ?? '')));
        $hello = $name === '' ? 'Hola' : "Hola {$name}";
        $store = $order->store->name;
        $pickup = $order->delivery_method === DeliveryMethod::Pickup;

        return match ($order->status) {
            OrderStatus::Paid => implode("\n", [
                "{$hello}, recibimos el pago de tu pedido {$order->number} por ".Number::currency((float) $order->total).'. ¡Gracias!',
                $pickup ? 'Te avisamos en cuanto esté listo para recoger.' : 'Te avisamos en cuanto vaya en camino.',
                $store,
            ]),
            OrderStatus::Shipped => $pickup
                ? implode("\n", [
                    "{$hello}, tu pedido {$order->number} ya está listo para recoger en {$store}.",
                    'Al pasar por él menciona tu número de pedido.',
                ])
                : implode("\n", array_filter([
                    "{$hello}, tu pedido {$order->number} ya va en camino.",
                    filled($order->tracking_carrier) ? "Paquetería: {$order->tracking_carrier}" : null,
                    filled($order->tracking_number) ? "Guía: {$order->tracking_number}" : null,
                    $store,
                ])),
            OrderStatus::Delivered => implode("\n", [
                "{$hello}, tu pedido {$order->number} fue entregado.",
                "¡Gracias por tu compra en {$store}!",
            ]),
            default => null,
        };
    }

    /** Enlace wa.me al teléfono del cliente con el aviso del estado actual. Null si no hay aviso o no hay teléfono. */
    public static function statusNoticeUrl(Order $order): ?string
    {
        $message = self::statusNotice($order);

        return $message === null ? null : WhatsAppLink::url($order->contactPhone(), $message);
    }

    /** Mensaje corto para preguntar algo del pedido. */
    public static function question(Order $order): string
    {
        return "Hola, tengo una duda sobre mi pedido {$order->number}.";
    }
}
