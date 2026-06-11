<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // history_id dari generate-qris (int, disimpan string) untuk cek payment-status.
            $table->string('qris_history_id')->nullable()->after('komerce_order_id');
            // final_amount = nominal + kode unik QRISLY (yang harus dibayar pelanggan).
            $table->unsignedInteger('qris_amount')->nullable()->after('qris_history_id');
            // qris_string mentah (untuk render QR di FE).
            $table->text('qris_string')->nullable()->after('qris_amount');
            // unpaid / paid / expired / cancelled.
            $table->string('qris_status', 20)->nullable()->after('qris_string');
            $table->timestamp('qris_expired_at')->nullable()->after('qris_status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['qris_history_id', 'qris_amount', 'qris_string', 'qris_status', 'qris_expired_at']);
        });
    }
};
