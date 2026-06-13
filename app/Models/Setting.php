<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    /**
     * Apakah fitur kode unik aktif. Sumber: setting DB `unique_code_enabled`
     * (bisa di-toggle admin di Pengaturan Toko). Default aktif kalau belum di-set.
     */
    public static function uniqueCodeEnabled(): bool
    {
        return filter_var(static::get('unique_code_enabled', '1'), FILTER_VALIDATE_BOOLEAN);
    }

    /** Metode pembayaran transfer manual aktif (default aktif). */
    public static function paymentTransferEnabled(): bool
    {
        return filter_var(static::get('payment_transfer_enabled', '1'), FILTER_VALIDATE_BOOLEAN);
    }

    /** Metode pembayaran QRIS aktif (default MATI; baru aktif setelah upload QRIS toko & dicentang). */
    public static function paymentQrisEnabled(): bool
    {
        return filter_var(static::get('payment_qris_enabled', '0'), FILTER_VALIDATE_BOOLEAN);
    }
}
