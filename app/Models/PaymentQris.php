<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentQris extends Model
{
    protected $table = 'payment_qris';

    protected $fillable = [
        'qris_id',
        'name',
        'merchant_name',
        'provider',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** QRIS yang sedang aktif (dipakai untuk generate). */
    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->latest('id')->first();
    }
}
