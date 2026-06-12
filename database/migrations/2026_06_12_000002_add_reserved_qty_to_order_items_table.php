<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            // Berapa unit stok yang SEDANG ditahan (reserved) oleh baris item ini.
            // Di-set = quantity saat order dibuat (potong stok varian), di-nol-kan saat
            // order dibatalkan (stok dikembalikan). Per-item supaya mendukung edit order
            // (ubah qty / hapus sebagian item) tanpa melepas reservasi item lain.
            $table->unsignedInteger('reserved_qty')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('reserved_qty');
        });
    }
};
