<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResellerCommission extends Model
{
    protected $fillable = [
        'reseller_id',
        'order_id',
        'order_code',
        'order_items_total',
        'commission_type',
        'commission_rate',
        'amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'order_items_total' => 'integer',
            'commission_rate' => 'integer',
            'amount' => 'integer',
        ];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
