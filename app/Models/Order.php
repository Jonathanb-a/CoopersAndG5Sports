<?php

namespace App\Models;

use App\Enums\CancelledBy;
use App\Enums\DeliveryAttemptResult;
use App\Enums\DeliveryMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Models\Concerns\BelongsToStore;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Order extends Model
{
    use BelongsToStore;

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
        'paid_at',
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
            'paid_at' => 'datetime',
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
