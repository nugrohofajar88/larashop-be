<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RajaOngkirTopup extends Model
{
    protected $fillable = [
        'amount',
        'topup_date',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'topup_date' => 'date',
        ];
    }
}
