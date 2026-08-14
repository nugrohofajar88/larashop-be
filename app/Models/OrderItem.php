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
        'reserved_qty',
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
            'reserved_qty' => 'integer',
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

    /**
     * Sinkron price/subtotal ke harga live varian (bukan "dijanjikan" dari
     * kapan item dimasukkan ke keranjang - kalau admin ubah harga, checkout
     * harus pakai harga terbaru, sama seperti marketplace pada umumnya).
     * Return true kalau harganya berubah.
     */
    public function syncPriceFromVariant(): bool
    {
        $livePrice = (int) ($this->variant?->price ?? $this->price);

        if ($livePrice === (int) $this->price) {
            return false;
        }

        $this->update([
            'price' => $livePrice,
            'subtotal' => $livePrice * $this->quantity,
        ]);

        return true;
    }
}
