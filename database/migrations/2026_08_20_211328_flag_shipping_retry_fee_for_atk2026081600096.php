<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data fix (bukan schema) - kasus ke-11 (terakhir) dari rekonsiliasi retry-
     * booking. ID hilang 25950 sempat ambigu antara ATK2026081600095 (id 25952,
     * cancelled, sudah dapat refund terpisah) dan ATK2026081600096 (id 25951, AWB
     * C1SYVYTY). Dipastikan lewat pola konsisten di 10 kasus lain: ID hilang selalu
     * tepat 1 angka SEBELUM id order asli (25950 = 25951-1) - jadi order asalnya
     * ATK2026081600096. Menutup total gap Rp267.602 sepenuhnya (persis cocok ke
     * mutasi RajaOngkir 20 Agt 2026).
     */
    public function up(): void
    {
        DB::table('orders')->where('code', 'ATK2026081600096')->update([
            'shipping_retry_fee' => 5992,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('orders')->where('code', 'ATK2026081600096')->update([
            'shipping_retry_fee' => null,
        ]);
    }
};
