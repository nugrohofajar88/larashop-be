<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailLog extends Model
{
    protected $fillable = [
        'order_id',
        'to_email',
        'subject',
        'status',
        'error',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
