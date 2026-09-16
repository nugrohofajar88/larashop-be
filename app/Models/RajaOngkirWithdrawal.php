<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RajaOngkirWithdrawal extends Model
{
    protected $fillable = [
        'amount',
        'withdrawn_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'withdrawn_at' => 'datetime',
        ];
    }
}
