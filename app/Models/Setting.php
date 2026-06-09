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
}
