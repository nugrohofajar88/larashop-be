<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'product_sku',
        'variant_label',
        'weight_grams',
        'price',
        'quantity',
        'subtotal',
        'is_selected',
    ];

    protected function casts(): array
    {
        return [
            'product_variant_id' => 'integer',
            'weight_grams' => 'integer',
            'price' => 'integer',
            'quantity' => 'integer',
            'subtotal' => 'integer',
            'is_selected' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
