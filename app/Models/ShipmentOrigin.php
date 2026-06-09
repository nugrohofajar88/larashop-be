<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShipmentOrigin extends Model
{
    use HasFactory;

    protected $fillable = [
        'label',
        'contact_name',
        'contact_phone',
        'origin_id',
        'selected_courier',
        'province',
        'city',
        'district',
        'subdistrict',
        'postal_code',
        'address_line',
        'pin_point',
        'note',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'origin_id' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
