<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMethod;
use App\Http\Middleware\ResolveStore;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Store;
use App\Services\CouponResult;
use App\Support\OrderWhatsAppMessage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Confirmación del pedido apartado: /pedido/{numero}/gracias. Solo la ve el cliente dueño del pedido. */
class OrderConfirmationController extends Controller
{
    public function __invoke(Request $request, string $number): Response
    {
        /** @var Store $store */
        $store = app(ResolveStore::CONTAINER_KEY);

        // De esta tienda y de este cliente; cualquier otro pedido responde 404, exista o no.
        $order = Order::query()
            ->where('store_id', $store->id)
            ->where('customer_id', $request->user('customer')->id)
            ->where('number', $number)
            ->with([
                'items.productVariant' => fn ($query) => $query->withTrashed()
                    ->with(['product' => fn ($product) => $product->withoutGlobalScope('store')->withTrashed()]),
                'coupon',
                'customer',
                'store',
            ])
            ->firstOrFail();

        return Inertia::render('order-thanks', [
            'order' => [
                'number' => $order->number,
                'status' => $order->status->getLabel(),
                'delivery_method' => $order->delivery_method->value,
                // Los pedidos anteriores a elegir la forma de pago eran por transferencia.
                'payment_method' => $order->payment_method?->value ?? 'transfer',
                'delivery_label' => $order->delivery_method->getLabel(),
                'shipping_address' => $order->shipping_address,
                'notes' => $order->notes,
                'coupon' => $order->coupon?->code,
                'subtotal' => $order->subtotal,
                'discount' => $order->discount,
                'shipping_cost' => $order->shipping_cost,
                'total' => $order->total,
                'expires_at' => $order->expires_at?->toIso8601String(),
                // Fecha límite de pago ya escrita en español y en la hora de México.
                'expires_at_text' => $order->expires_at?->timezone(config('app.timezone'))->translatedFormat('l j \d\e F \d\e Y, H:i'),
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'name' => $item->product_name,
                    'size' => $item->size,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => CouponResult::money((int) round((float) $item->unit_price * 100) * $item->quantity),
                    'is_backorder' => $item->is_backorder,
                ])->all(),
            ],
            // Dónde y cuándo recoger, solo si el pedido se recoge en tienda.
            'pickup' => $order->delivery_method === DeliveryMethod::Pickup ? [...$store->pickupInfo(), 'schedule' => $order->pickupSchedule()] : null,
            'bankInfo' => trim((string) $store->bank_info) ?: null,
            // WhatsApp de la tienda del pedido, con el mensaje ya escrito. Null si la tienda no tiene número.
            'whatsapp' => [
                // El comprobante es solo de la transferencia; en efectivo se paga al entregar.
                'receipt_url' => $order->paysInCash() ? null : $order->store->whatsappUrl(OrderWhatsAppMessage::receipt($order)),
                'question_url' => $order->store->whatsappUrl(OrderWhatsAppMessage::question($order)),
            ],
        ]);
    }
}
