<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un cambio de estado de un pedido. Solo tiene created_at: una vez escrito no se edita. */
class OrderStatusHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'user_id',
        'customer_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Quién hizo el cambio, para mostrarlo en el panel. */
    public function actorLabel(): string
    {
        return match (true) {
            $this->user !== null => $this->user->name,
            $this->customer !== null => "{$this->customer->name} (cliente)",
            default => 'Sistema',
        };
    }
}
