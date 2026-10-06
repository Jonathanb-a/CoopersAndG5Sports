<?php

namespace App\Models;

use App\Enums\DeliveryAttemptResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un intento de entrega a domicilio. Lo escribe solo OrderDeliveryService. */
class DeliveryAttempt extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'result',
        'notes',
        'attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => DeliveryAttemptResult::class,
            'attempted_at' => 'datetime',
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
}
