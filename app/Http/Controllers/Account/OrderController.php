<?php

namespace App\Http\Controllers\Account;

use App\Enums\DeliveryMethod;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveStore;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Store;
use App\Services\CancellationRequestService;
use App\Services\CouponResult;
use App\Services\OrderCancellationService;
use App\Services\OrderDeliveryService;
use App\Support\OrderWhatsAppMessage;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Mis pedidos»: los pedidos del cliente en ESTA tienda. Un pedido de otro cliente o de la otra
 * tienda responde 404. Al cliente nunca se le mandan notas internas ni nombres del personal.
 */
class OrderController extends Controller
{
    private const PER_PAGE = 10;

    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->where('store_id', $this->store()->id)
            ->where('customer_id', $request->user('customer')->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'pagina');

        return Inertia::render('my-orders', [
            'orders' => $orders->getCollection()->map(fn (Order $order) => [
                'number' => $order->number,
                'date' => $this->date($order->created_at),
                'total' => $order->total,
                'status' => $order->status->value,
                'status_label' => $order->statusLabel(),
                'expiring_label' => $order->expiringLabel(),
            ])->all(),
            'pagination' => [
                'current' => $orders->currentPage(),
                'last' => $orders->lastPage(),
                'total' => $orders->total(),
                'prev_url' => $orders->previousPageUrl(),
                'next_url' => $orders->nextPageUrl(),
            ],
        ]);
    }

    public function show(Request $request, string $number): Response
    {
        $store = $this->store();
        $order = $this->find($request, $number);

        $order->load([
            'items.productVariant' => fn ($query) => $query->withTrashed()
                ->with(['product' => fn ($product) => $product->withoutGlobalScope('store')->withTrashed()->with('images')]),
            'coupon',
            'customer',
            'store',
            'statusHistories',
            'deliveryAttempts',
        ]);

        $reserved = $order->status === OrderStatus::Reserved;

        return Inertia::render('my-order', [
            'order' => [
                'number' => $order->number,
                'status' => $order->status->value,
                'status_label' => $order->statusLabel(),
                'expiring_label' => $order->expiringLabel(),
                'date' => $this->dateTime($order->created_at),
                'delivery_label' => $order->delivery_method->getLabel(),
                'shipping_address' => $order->shipping_address,
                'notes' => $order->notes,
                'delivery_preference' => $order->deliveryPreference(),
                'coupon' => $order->coupon?->code,
                'subtotal' => $order->subtotal,
                'discount' => $order->discount,
                'shipping_cost' => $order->shipping_cost,
                'total' => $order->total,
                'tracking' => filled($order->tracking_number)
                    ? ['carrier' => $order->tracking_carrier, 'number' => $order->tracking_number]
                    : null,
                // Solo mientras está apartado importa la fecha límite de pago.
                'expires_at' => $reserved ? $order->expires_at?->toIso8601String() : null,
                'expires_at_text' => $reserved && $order->expires_at ? $this->dateTime($order->expires_at) : null,
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'name' => $item->product_name,
                    'size' => $item->size,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => CouponResult::money((int) round((float) $item->unit_price * 100) * $item->quantity),
                    'is_backorder' => $item->is_backorder,
                    'image_url' => ($path = $item->productVariant?->product?->images->first()?->path)
                        ? Storage::disk('public')->url($path)
                        : null,
                ])->all(),
                'timeline' => $this->timeline($order),
            ],
            'can' => [
                'cancel' => $reserved,
                'request_cancellation' => CancellationRequestService::canRequest($order),
            ],
            'delivery' => $this->delivery($order),
            // Dónde y cuándo recoger, solo si el pedido se recoge en tienda.
            'pickup' => $order->delivery_method === DeliveryMethod::Pickup ? $store->pickupInfo() : null,
            'cancellationRequested' => CancellationRequestService::isPending($order),
            'bankInfo' => $reserved ? (trim((string) $store->bank_info) ?: null) : null,
            'whatsapp' => [
                'receipt_url' => $reserved ? $order->store->whatsappUrl(OrderWhatsAppMessage::receipt($order)) : null,
                'question_url' => $order->store->whatsappUrl(OrderWhatsAppMessage::question($order)),
            ],
            'status' => $request->session()->get('status'),
        ]);
    }

    /** Cancela un pedido apartado: regresa las piezas y queda cancelado por el cliente. */
    public function cancel(Request $request, string $number, OrderCancellationService $cancellations): RedirectResponse
    {
        $order = $this->find($request, $number);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']], [], ['reason' => 'motivo']);

        // Un pedido pagado no se cancela desde aquí: solo se solicita.
        if ($order->status !== OrderStatus::Reserved) {
            return $this->back($order)->withErrors(['order' => 'Este pedido ya no se puede cancelar desde aquí.']);
        }

        try {
            $cancellations->cancel($order, $request->user('customer'), $data['reason'] ?? null, restock: true);
        } catch (InvalidOrderTransitionException) {
            return $this->back($order)->withErrors(['order' => 'Este pedido ya no se puede cancelar desde aquí.']);
        }

        return $this->back($order)->with('status', 'Cancelamos tu pedido.');
    }

    /** Pide cancelar un pedido pagado. No cancela: avisa al personal, que decide. */
    public function requestCancellation(Request $request, string $number, CancellationRequestService $requests): RedirectResponse
    {
        $order = $this->find($request, $number);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']], [], ['note' => 'nota']);

        try {
            $requests->request($order, $request->user('customer'), $data['note'] ?? null);
        } catch (InvalidOrderTransitionException) {
            return $this->back($order)->withErrors(['order' => CancellationRequestService::isPending($order->refresh())
                ? 'Ya tienes una solicitud de cancelación en revisión para este pedido.'
                : 'Este pedido ya no admite una solicitud de cancelación.']);
        }

        return $this->back($order)->with('status', 'Recibimos tu solicitud. La estamos revisando.');
    }

    /** El pedido, solo si es de esta tienda y de este cliente. */
    private function find(Request $request, string $number): Order
    {
        return Order::query()
            ->where('store_id', $this->store()->id)
            ->where('customer_id', $request->user('customer')->id)
            ->where('number', $number)
            ->firstOrFail();
    }

    /**
     * Línea de tiempo para el cliente: solo los cambios de estado con su fecha. Se dejan fuera las
     * anotaciones del personal (mismo estado antes y después), las notas y quién hizo cada cambio.
     *
     * @return list<array{label: string, date: string}>
     */
    private function timeline(Order $order): array
    {
        return $order->statusHistories
            ->filter(fn (OrderStatusHistory $row) => $row->from_status !== $row->to_status)
            ->map(fn (OrderStatusHistory $row) => [
                'label' => $row->to_status->labelFor($order->delivery_method),
                'date' => $this->dateTime($row->created_at),
            ])
            ->values()
            ->all();
    }

    /**
     * Entrega a domicilio de un pedido local en curso: la fecha y el horario acordados, o el aviso de
     * que el último intento falló. Sin notas de los intentos ni quién los registró.
     *
     * @return array{scheduled: ?array{date: string, window: ?string}, failed: bool, reschedule_url: ?string}
     */
    private function delivery(Order $order): array
    {
        $scheduled = OrderDeliveryService::canSchedule($order) && $order->delivery_scheduled_date !== null;
        $failed = $order->needsDeliveryReschedule();

        return [
            'scheduled' => $scheduled
                ? ['date' => OrderWhatsAppMessage::deliveryDate($order), 'window' => $order->delivery_window]
                : null,
            'failed' => $failed,
            'reschedule_url' => $failed ? $order->store->whatsappUrl(OrderWhatsAppMessage::reschedule($order)) : null,
        ];
    }

    private function back(Order $order): RedirectResponse
    {
        return to_route('account.orders.show', ['number' => $order->number]);
    }

    /** "5 de octubre de 2026" */
    private function date(CarbonInterface $date): string
    {
        return $date->timezone(config('app.timezone'))->translatedFormat('j \d\e F \d\e Y');
    }

    /** "5 de octubre de 2026, 10:30" */
    private function dateTime(CarbonInterface $date): string
    {
        return $date->timezone(config('app.timezone'))->translatedFormat('j \d\e F \d\e Y, H:i');
    }

    private function store(): Store
    {
        return app(ResolveStore::CONTAINER_KEY);
    }
}
