<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrisGenerationLog extends Model
{
    protected $fillable = [
        'order_id',
        'fee',
    ];

    protected function casts(): array
    {
        return [
            'fee' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
