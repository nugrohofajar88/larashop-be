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
        'status',
        'payment_method',
        'payment_status',
        'items_total',
        'shipping_total',
        'shipping_cashback',
        'unique_code',
        'used_unique_code',
        'grand_total',
        'shipping_service_name',
        'shipping_courier_code',
        'shipping_service_code',
        'shipping_estimate_days',
        'awb',
        'komerce_order_no',
        'komerce_order_id',
        'qris_history_id',
        'qris_amount',
        'qris_string',
        'qris_status',
        'qris_expired_at',
        'shipment_note',
        'paid_at',
        'shipped_at',
        'cancel_requested_at',
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
            'shipping_cashback' => 'integer',
            'unique_code' => 'integer',
            'used_unique_code' => 'integer',
            'grand_total' => 'integer',
            'qris_amount' => 'integer',
            'qris_expired_at' => 'datetime',
            'paid_at' => 'datetime',
            'shipped_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function trackings(): HasMany
    {
        return $this->hasMany(OrderTracking::class)->orderBy('id');
    }

    /**
     * Catat satu perubahan status ke riwayat (order_trackings).
     * Anti-duplikat: lewati kalau status terakhir sama persis.
     *
     * @param  array{raw_status?:string|null,awb?:string|null,note?:string|null}  $opts
     */
    public function logTracking(string $status, string $source = 'app', array $opts = []): void
    {
        $last = $this->trackings()->reorder('id', 'desc')->first();

        if ($last !== null && $last->status === $status && ($opts['raw_status'] ?? null) === $last->raw_status) {
            return;
        }

        $this->trackings()->create([
            'status' => $status,
            'source' => $source,
            'raw_status' => $opts['raw_status'] ?? null,
            'awb' => $opts['awb'] ?? $this->awb,
            'note' => $opts['note'] ?? null,
            'created_at' => now(),
        ]);
    }
}
