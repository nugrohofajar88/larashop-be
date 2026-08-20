<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data fix (bukan schema) - flag 4 order yang shipping real-nya ke ekspedisi
     * beda dari yang tercatat di sistem, hasil rekonsiliasi manual ke file mutasi
     * RajaOngkir/Komerce (20 Agt 2026) & screenshot detail order Komerce. Kolom
     * shipping_total/shipping_cashback ASLI tetap dipertahankan (itu yang beneran
     * di-charge ke customer) - shipping_actual_value cuma buat jejak kalau
     * ekspedisi nagih/koreksi susulan di akhir bulan.
     */
    private array $flags = [
        'ATK2026081000035' => [
            'shipping_actual_value' => 22500,
            'shipping_discrepancy_note' => 'Real ekspedisi (net cashback) Rp22.500, sistem catat Rp37.500 (lebih besar Rp15.000). Tervalidasi dari mutasi RajaOngkir 20 Agt 2026.',
        ],
        'ATK2026081400085' => [
            'shipping_actual_value' => 89250,
            'shipping_discrepancy_note' => 'Real ekspedisi (net cashback) Rp89.250, sistem catat Rp127.500 (lebih besar Rp38.250). Tervalidasi dari mutasi RajaOngkir 20 Agt 2026 & screenshot detail order Komerce.',
        ],
        'ATK2026081500088' => [
            'shipping_actual_value' => 16500,
            'shipping_discrepancy_note' => 'Real ekspedisi (net cashback) Rp16.500, sistem catat Rp41.250 (lebih besar Rp24.750). Tervalidasi dari mutasi RajaOngkir 20 Agt 2026.',
        ],
        'ATK2026081100059' => [
            'shipping_actual_value' => 41250,
            'shipping_discrepancy_note' => 'COD - real ekspedisi (net cashback) Rp41.250, sistem catat Rp33.000 (lebih kecil Rp8.250, dari bug weight_grams Sodium Humate 5kg=600g bukan 5100g). Tervalidasi dari mutasi RajaOngkir 20 Agt 2026.',
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->flags as $code => $values) {
            DB::table('orders')->where('code', $code)->update([
                'shipping_actual_value' => $values['shipping_actual_value'],
                'shipping_discrepancy_note' => $values['shipping_discrepancy_note'],
                'shipping_reconciled_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('orders')->whereIn('code', array_keys($this->flags))->update([
            'shipping_actual_value' => null,
            'shipping_discrepancy_note' => null,
            'shipping_reconciled_at' => null,
        ]);
    }
};
