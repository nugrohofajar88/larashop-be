<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'label',
        'recipient_name',
        'recipient_phone',
        'destination_id',
        'province',
        'city',
        'district',
        'subdistrict',
        'postal_code',
        'address_line',
        'note',
        'is_primary',
        'latitude',
        'longitude',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

