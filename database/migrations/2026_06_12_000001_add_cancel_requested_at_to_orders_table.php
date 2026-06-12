<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Ditandai saat customer mengajukan pembatalan untuk order yang sudah 'paid'
            // (perlu konfirmasi admin). Order 'pending_payment' dibatalkan langsung tanpa flag ini.
            $table->timestamp('cancel_requested_at')->nullable()->after('shipped_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('cancel_requested_at');
        });
    }
};
