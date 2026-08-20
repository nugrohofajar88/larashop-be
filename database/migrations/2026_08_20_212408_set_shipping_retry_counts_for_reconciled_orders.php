<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Data fix (bukan schema) - lengkapi shipping_retry_count utk 11 order yang
     * sudah di-flag shipping_retry_fee sebelumnya, biar tabel detail bisa tampilkan
     * "Biaya per Retry" x "Banyak Retry" = Total, bukan cuma total gabungannya.
     */
    private array $retryCounts = [
        'ATK2026080900008' => 1,
        'ATK2026080900012' => 1,
        'ATK2026081000033' => 1,
        'ATK2026081000035' => 1,
        'ATK2026081100045' => 2,
        'ATK2026081100053' => 1,
        'ATK2026081200071' => 1,
        'ATK2026081400079' => 2,
        'ATK2026081400080' => 1,
        'ATK2026081600091' => 1,
        'ATK2026081600096' => 1,
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->retryCounts as $code => $count) {
            DB::table('orders')->where('code', $code)->update([
                'shipping_retry_count' => $count,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('orders')->whereIn('code', array_keys($this->retryCounts))->update([
            'shipping_retry_count' => null,
        ]);
    }
};
