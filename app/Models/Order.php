<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'user_id',
        'customer_address_id',
        'shipping_service_id',
        'status',
        'payment_method',
        'payment_status',
        'items_total',
        'shipping_total',
        'unique_code',
        'used_unique_code',
        'grand_total',
        'shipping_service_name',
        'shipping_estimate_days',
        'awb',
        'shipment_note',
        'paid_at',
        'shipped_at',
        'recipient_name',
        'recipient_phone',
        'address_label',
        'address_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'items_total' => 'integer',
            'shipping_total' => 'integer',
            'unique_code' => 'integer',
            'used_unique_code' => 'integer',
            'grand_total' => 'integer',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class, 'customer_address_id');
    }

    public function shippingService(): BelongsTo
    {
        return $this->belongsTo(ShippingService::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
