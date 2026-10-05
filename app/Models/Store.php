<?php

namespace App\Models;

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
        'about_text',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** Número de WhatsApp en formato internacional, solo dígitos. Un número de 10 dígitos se toma como mexicano (+52). */
    public function whatsappNumber(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->whatsapp_phone);

        return strlen($digits) < 10 ? null : (strlen($digits) === 10 ? '52'.$digits : $digits);
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
