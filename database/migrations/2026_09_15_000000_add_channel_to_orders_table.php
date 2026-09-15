<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // 'web' | 'whatsapp' | null (order lama, sebelum kolom ini ada -
            // laporan fallback ke deteksi kode-order-di-transkrip-WA utk ini).
            $table->string('channel', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('channel');
        });
    }
};
