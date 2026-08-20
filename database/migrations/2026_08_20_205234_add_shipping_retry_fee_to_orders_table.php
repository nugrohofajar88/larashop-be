<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Biaya TAMBAHAN dari percobaan booking yang gagal sebelum akhirnya
            // berhasil dapat AWB - beda dari shipping_actual_value (yang mengoreksi
            // nilai shipping_total yang salah). Ini murni biaya ekstra yang beneran
            // kepotong dari saldo deposit RajaOngkir/Komerce, ketemu dari rekonsiliasi
            // manual ke file mutasi (baris tanpa Resi/AWB, ID booking mepet dgn order
            // asli, nilai identik - indikasi API booking dipanggil berkali-kali).
            $table->unsignedInteger('shipping_retry_fee')->nullable()->after('shipping_reconciled_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shipping_retry_fee');
        });
    }
};
