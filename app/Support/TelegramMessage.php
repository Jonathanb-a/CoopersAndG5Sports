<?php

namespace App\Support;

use App\Enums\DeliveryMethod;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Number;

/**
 * Textos de los avisos al dueño por Telegram. Todos empiezan con el nombre de la tienda y, cuando
 * aplica, el número de pedido. Nunca llevan costos ni datos de configuración.
 */
class TelegramMessage
{
    /**
     * Pedido nuevo de la tienda en línea. Al recoger en tienda solo dice eso; en cualquier otra
     * entrega (a domicilio) agrega a dónde va, quién recibe y el teléfono.
     */
    public static function newOrder(Order $order, Store $store, ?Customer $customer): string
    {
        $lines = [
            "{$store->name} · {$order->number} · Pedido nuevo",
            'Cliente: '.($customer?->name ?? '—'),
            'Total: '.Number::currency((float) $order->total),
            'Entrega: '.$order->delivery_method->getLabel(),
        ];

        if ($order->delivery_method !== DeliveryMethod::Pickup) {
            $address = $order->shipping_address ?? [];
            $point = implode(', ', array_filter([$address['street'] ?? null, $address['neighborhood'] ?? null, $address['city'] ?? null]));

            $lines = [...$lines, ...array_filter([
                $point !== '' ? "Dirección: {$point}" : null,
                filled($address['notes'] ?? null) ? "Referencias: {$address['notes']}" : null,
                filled($address['name'] ?? null) ? "Recibe: {$address['name']}" : null,
                ($phone = $order->contactPhone()) ? "Teléfono: {$phone}" : null,
            ])];
        }

        // Por transferencia (lo normal) o en efectivo, que se cobra al entregar o al recoger y no vence.
        if ($order->paysInCash()) {
            $lines[] = 'Pago: Efectivo (se cobra '.($order->delivery_method === DeliveryMethod::Pickup ? 'al recoger' : 'al entregar').')';
            $lines[] = 'Confirmar antes de: '.($order->expires_at ? $order->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') : '—');
        } else {
            $lines[] = 'Pago: '.($order->payment_method?->getLabel() ?? PaymentMethod::Transfer->getLabel());
            $lines[] = 'Paga antes de: '.($order->expires_at ? $order->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') : '—');
        }

        return implode("\n", $lines);
    }

    /** Un pedido en efectivo ya confirmado pasó su fecha sin entregarse. No se cancela solo. */
    public static function cashOrderOverdue(Order $order, Store $store, ?Customer $customer): string
    {
        return implode("\n", [
            "{$store->name} · {$order->number} · Pedido en efectivo sin entregar",
            'Cliente: '.($customer?->name ?? '—'),
            'Por cobrar: '.Number::currency((float) $order->total),
            'Entrega: '.$order->delivery_method->getLabel(),
            'Pasó su fecha: '.($order->expires_at ? $order->expires_at->timezone(config('app.timezone'))->format('d/m/Y H:i') : '—'),
        ]);
    }

    /** El cliente pidió cancelar un pedido ya pagado. */
    public static function cancellationRequest(Order $order, Store $store, Customer $customer, ?string $note): string
    {
        return implode("\n", [
            "{$store->name} · {$order->number} · Solicitud de cancelación",
            "Cliente: {$customer->name}",
            'Motivo: '.(filled($note) ? $note : 'no dio motivo'),
        ]);
    }

    /** Todas las tallas de un producto quedaron en 0. */
    public static function soldOut(Product $product, Store $store): string
    {
        $name = filled($product->color) ? "{$product->name} ({$product->color})" : $product->name;

        return "{$store->name} · Modelo agotado\nSe agotó {$name} en {$store->name}.";
    }
}
