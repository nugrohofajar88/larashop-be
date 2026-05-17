<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingService extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'service_level',
        'estimate_days',
        'price',
        'description',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
