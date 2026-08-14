<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('printed_at')->nullable()->after('shipped_at');
            $table->string('printed_by')->nullable()->after('printed_at');
        });

        // Backfill: order yang sudah shipped/completed PASTI sudah butuh label
        // ditempel ke paketnya - anggap "sudah dicetak" sejak shipped_at, supaya
        // data lama tidak salah kelihatan "belum dicetak". Order yang masih
        // paid/processing sengaja dibiarkan kosong (belum tentu sudah dicetak).
        DB::table('orders')
            ->whereIn('status', ['shipped', 'completed'])
            ->whereNotNull('shipped_at')
            ->update([
                'printed_at' => DB::raw('shipped_at'),
                'printed_by' => 'Data lama',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['printed_at', 'printed_by']);
        });
    }
};
