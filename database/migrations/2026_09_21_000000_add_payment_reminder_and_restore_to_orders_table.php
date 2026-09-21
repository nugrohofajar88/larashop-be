<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // 0 = belum ada pengingat, 1 = pengingat pertama terkirim, 2 = pengingat terakhir terkirim.
            $table->unsignedTinyInteger('payment_reminder_stage')->default(0)->after('payment_status');
            // Diisi saat admin memulihkan order yang batal otomatis - jadi acuan
            // tenggat bayar baru (menggantikan created_at).
            $table->timestamp('restored_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['payment_reminder_stage', 'restored_at']);
        });
    }
};
