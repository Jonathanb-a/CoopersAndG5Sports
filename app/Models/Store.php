<?php

namespace App\Models;

use App\Support\WhatsAppLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'domain',
        'theme',
        'whatsapp_phone',
        'telegram_chat_id',
        'bank_info',
        'local_shipping_cost',
        'national_shipping_cost',
        'about_text',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'local_shipping_cost' => 'decimal:2',
            'national_shipping_cost' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /** Enlace de WhatsApp de la tienda (wa.me), con un mensaje prellenado opcional. Null si no tiene número. */
    public function whatsappUrl(?string $message = null): ?string
    {
        return WhatsAppLink::url($this->whatsapp_phone, $message);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function banners(): HasMany
    {
        return $this->hasMany(Banner::class);
    }
}
