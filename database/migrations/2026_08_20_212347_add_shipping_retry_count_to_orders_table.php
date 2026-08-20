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
            // Jumlah percobaan booking yang gagal sebelum akhirnya berhasil dapat AWB
            // (shipping_retry_fee = total biaya dari SEMUA percobaan gagal ini gabung).
            $table->unsignedTinyInteger('shipping_retry_count')->nullable()->after('shipping_retry_fee');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('shipping_retry_count');
        });
    }
};
