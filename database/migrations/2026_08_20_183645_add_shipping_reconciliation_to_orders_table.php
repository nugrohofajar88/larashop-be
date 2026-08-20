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
            // Ongkir REAL yang beneran di-charge ekspedisi (setelah cashback), dari
            // verifikasi manual ke mutasi transaksi RajaOngkir/Komerce - TERPISAH dari
            // shipping_total/shipping_cashback asli (yang tetap dipertahankan buat
            // histori "apa yang di-charge ke customer"). Dipakai buat nge-flag order
            // yang datanya beda dari yang beneran di-booking, supaya kalau ekspedisi
            // nagih susulan di akhir bulan (mis. karena berat produk salah), kita
            // sudah punya jejaknya duluan - bukan kaget pas ditagih.
            $table->unsignedInteger('shipping_actual_value')->nullable()->after('shipping_cashback');
            $table->text('shipping_discrepancy_note')->nullable()->after('shipping_actual_value');
            $table->timestamp('shipping_reconciled_at')->nullable()->after('shipping_discrepancy_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_actual_value', 'shipping_discrepancy_note', 'shipping_reconciled_at']);
        });
    }
};
