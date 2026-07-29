<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Biaya jasa COD dari Komerce (~2,8%), ditanggung toko (dipotong dari
            // net_income), BUKAN ditambahkan ke tagihan pembeli. 0 utk order non-COD.
            // Disimpan saat booking Komerce sukses, dipakai utk laporan admin.
            $table->unsignedBigInteger('cod_service_fee')->default(0)->after('shipping_cashback');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('cod_service_fee');
        });
    }
};
