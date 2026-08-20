<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data fix (bukan schema) - catat biaya retry-booking utk 10 order yang
     * tervalidasi dari file mutasi RajaOngkir/Komerce (20 Agt 2026): baris tanpa
     * Resi/AWB dgn ID booking mepet dgn ID order asli & nilai identik, indikasi
     * API booking sempat dipanggil berkali-kali sebelum berhasil. 1 kasus lain
     * (Rp5.992, kandidat ATK2026081600095/096) sengaja TIDAK di-flag krn masih
     * ambigu antara 2 order dgn nilai sama.
     */
    private array $retryFees = [
        'ATK2026080900008' => 8250,
        'ATK2026080900012' => 13500,
        'ATK2026081000033' => 41250,
        'ATK2026081000035' => 22500,
        'ATK2026081100045' => 49500,
        'ATK2026081100053' => 5992,
        'ATK2026081200071' => 5992,
        'ATK2026081400079' => 71876,
        'ATK2026081400080' => 28500,
        'ATK2026081600091' => 14250,
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->retryFees as $code => $fee) {
            DB::table('orders')->where('code', $code)->update([
                'shipping_retry_fee' => $fee,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('orders')->whereIn('code', array_keys($this->retryFees))->update([
            'shipping_retry_fee' => null,
        ]);
    }
};
