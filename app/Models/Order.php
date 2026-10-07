<?php

namespace App\Models;

use App\Enums\CancelledBy;
use App\Enums\DeliveryAttemptResult;
use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Concerns\BelongsToStore;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Order extends Model
{
    use BelongsToStore;

    /** Con menos de estas horas para pagar, al cliente se le marca el apartado como por vencer. */
    public const EXPIRING_SOON_HOURS = 12;

    /** Se enciende solo mientras OrderStatusService guarda un cambio de estado. */
    private static bool $statusChangeAllowed = false;

    protected $fillable = [
        'store_id',
        'customer_id',
        'number',
        'channel',
        'status',
        'delivery_method',
        'shipping_address',
        'subtotal',
        'discount',
        'shipping_cost',
        'total',
        'coupon_id',
        'expires_at',
        'cash_overdue_notified_at',
        'paid_at',
        'payment_method',
        'payment_reference',
        'delivered_at',
        'delivery_scheduled_date',
        'delivery_window',
        'tracking_carrier',
        'tracking_number',
        'cancelled_at',
        'cancel_reason',
        'cancelled_by',
        'refunded_at',
        'cancellation_requested_at',
        'cancellation_request_note',
        'notes',
        'internal_notes',
    ];

    protected static function booted(): void
    {
        // El estado de un pedido existente solo lo cambia OrderStatusService, que valida la
        // transición y deja el historial. Cualquier otro intento se detiene aquí.
        static::updating(function (Order $order) {
            if ($order->isDirty('status') && ! self::$statusChangeAllowed) {
                throw new LogicException('El estado de un pedido solo se cambia con OrderStatusService::transition().');
            }
        });
    }

    /** Lo usa OrderStatusService para guardar un cambio de estado. No llamar desde otro lado. */
    public static function withStatusChange(Closure $callback): mixed
    {
        self::$statusChangeAllowed = true;

        try {
            return $callback();
        } finally {
            self::$statusChangeAllowed = false;
        }
    }

    protected function casts(): array
    {
        return [
            'channel' => OrderChannel::class,
            'status' => OrderStatus::class,
            'delivery_method' => DeliveryMethod::class,
            'shipping_address' => 'array',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'expires_at' => 'datetime',
            'cash_overdue_notified_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_method' => PaymentMethod::class,
            'delivered_at' => 'datetime',
            'delivery_scheduled_date' => 'date',
            'cancelled_at' => 'datetime',
            'cancelled_by' => CancelledBy::class,
            'refunded_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
        ];
    }

    /** Estado como se le dice a la gente: "Enviado" es "Listo para recoger" o "En camino" según la entrega. */
    public function statusLabel(): string
    {
        return $this->status->labelFor($this->delivery_method);
    }

    /**
     * Para el cliente: "Vence en 5 horas" cuando a un apartado le quedan menos de 12 horas para
     * pagarse. Null si no está apartado o todavía tiene tiempo de sobra.
     */
    public function expiringLabel(): ?string
    {
        if ($this->status !== OrderStatus::Reserved || $this->expires_at === null) {
            return null;
        }

        $minutes = (int) floor(now()->diffInMinutes($this->expires_at, false));

        return match (true) {
            $minutes >= self::EXPIRING_SOON_HOURS * 60 => null,
            $minutes <= 0 => 'Venció el plazo de pago',
            $minutes < 60 => 'Vence en menos de 1 hora',
            $minutes < 120 => 'Vence en 1 hora',
            default => 'Vence en '.intdiv($minutes, 60).' horas',
        };
    }

    /** El cliente paga en efectivo al recibir o recoger (en la tienda en línea el resto paga por transferencia). */
    public function paysInCash(): bool
    {
        return $this->payment_method === PaymentMethod::Cash;
    }

    /** Lo más lejos que puede vencer un pedido en efectivo: shop.cash_reserve_max_days desde que se creó. */
    public function cashReserveCap(): CarbonInterface
    {
        return $this->created_at->copy()->addDays((int) config('shop.cash_reserve_max_days', 7));
    }

    /** Vencimiento de un pedido en efectivo con entrega ese día: el final del día, sin pasar del tope. */
    public function cashDeadlineFor(CarbonInterface $deliveryDate): CarbonInterface
    {
        $endOfDay = $deliveryDate->copy()->endOfDay();
        $cap = $this->cashReserveCap();

        return $endOfDay->gt($cap) ? $cap : $endOfDay;
    }

    /** Cancelado después de pagado y todavía sin devolverle el dinero al cliente. */
    public function refundPending(): bool
    {
        return $this->status === OrderStatus::Cancelled && $this->paid_at !== null && $this->refunded_at === null;
    }

    /** Teléfono para contactar al cliente: el que dejó para la entrega o el de su cuenta. */
    public function contactPhone(): ?string
    {
        return ($this->shipping_address['phone'] ?? null) ?: $this->customer?->phone;
    }

    /** Intentos fallidos de entrega a domicilio. */
    public function failedDeliveryAttempts(): int
    {
        return $this->deliveryAttempts->filter(fn (DeliveryAttempt $attempt) => $attempt->result->failed())->count();
    }

    /**
     * «Por reprogramar»: pedido local enviado cuyo último intento de entrega falló y que todavía no
     * tiene fecha nueva.
     */
    public function needsDeliveryReschedule(): bool
    {
        return $this->delivery_method === DeliveryMethod::Local
            && $this->status === OrderStatus::Shipped
            && $this->delivery_scheduled_date === null
            && (bool) $this->deliveryAttempts->last()?->result->failed();
    }

    /** Pedidos locales enviados cuyo último intento falló y siguen sin fecha. */
    public function scopeNeedsDeliveryReschedule(Builder $query): void
    {
        $lastResult = DeliveryAttempt::query()
            ->select('result')
            ->whereColumn('delivery_attempts.order_id', 'orders.id')
            ->orderByDesc('attempted_at')
            ->orderByDesc('id')
            ->limit(1);

        $query
            ->where('orders.delivery_method', DeliveryMethod::Local->value)
            ->where('orders.status', OrderStatus::Shipped->value)
            ->whereNull('orders.delivery_scheduled_date')
            ->where($lastResult, '!=', DeliveryAttemptResult::Delivered->value);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** Historial de estados, del más viejo al más nuevo. */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('id');
    }

    /** Intentos de entrega a domicilio, del más viejo al más nuevo. */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAttempt::class)->orderBy('attempted_at')->orderBy('id');
    }
}
